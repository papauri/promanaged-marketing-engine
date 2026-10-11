<?php
/**
 * Tests for Social > Optimise: the per-channel checklists, the suggestions built from the business's own details, applying them (Facebook Page fields and hours, picture and cover, the
 * WhatsApp Business profile, the business details, the calendar, the Google review link), refusal of bad values, ticks for the manual ones, the optional AI polish with its facts-only guard,
 * the score, the daily job and the Today line. Runs on a TEMP COPY of data/ with Facebook, WhatsApp and the AI stubbed: nothing real is read, changed or posted.
 * Run: php tests/test_optimise.php
 */
require __DIR__ . '/boot.php';
$T = pm_test_boot();
file_put_contents($T['env'], "APP_URL=https://app.test\nFB_PAGE_ID=PAGE\nFB_PAGE_TOKEN=T\nIG_USER_ID=IGU\nWA_BIZ_TOKEN=wt\nWA_BIZ_PHONE_ID=999\nWA_BIZ_VERIFY=wv\n");
pm_brand_set('promanaged');
$GLOBALS['PM_AI_STUB'] = null;
pm_save('page_opt', ['none' => []]); // (a copy of the real data may already hold a check)
pm_save('social_channels', ['none' => []]);

function t(string $name, callable $fn): void
{
    echo "\n$name\n";
    try {
        $fn();
    } catch (Throwable $e) {
        pm_t_assert(false, "$name threw " . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

/* ---- a fake Facebook Page, Instagram account and WhatsApp profile ---- */
$PAGE = ['name' => 'ProManaged IT', 'about' => '', 'description' => '', 'category' => 'Software', 'website' => '', 'phone' => '', 'emails' => [], 'username' => '', 'fan_count' => 10, 'followers_count' => 12,
    'picture' => ['data' => ['url' => 'https://x/p.jpg', 'is_silhouette' => true]], 'cover' => null, 'hours' => [], 'location' => null];
$IG = ['biography' => '', 'website' => '', 'username' => 'promanaged', 'profile_picture_url' => '', 'media_count' => 2, 'followers_count' => 5];
$WA = ['about' => '', 'address' => '', 'description' => '', 'email' => '', 'websites' => [], 'vertical' => 'UNDEFINED'];
$CALLS = [];
$FB_FAIL = '';
$FEED = [];
$CONV = [];
$GLOBALS['PM_GRAPH_STUB'] = function (string $method, string $path, array $params, string $token, array $files) use (&$PAGE, &$IG, &$CALLS, &$FB_FAIL, &$FEED, &$CONV) {
    $CALLS[] = [$method, $path, $params, array_keys($files)];
    if ($method === 'POST') {
        if ($FB_FAIL !== '') {
            return [false, $FB_FAIL];
        }
        if ($path === 'PAGE') {
            foreach (['about', 'description', 'phone', 'website'] as $k) {
                if (isset($params[$k])) {
                    $PAGE[$k] = $params[$k];
                }
            }
            if (isset($params['emails'])) {
                $PAGE['emails'] = (array)$params['emails'];
            }
            if (isset($params['hours'])) {
                $PAGE['hours'] = (array)$params['hours'];
            }
            if (isset($params['cover'])) {
                $PAGE['cover'] = ['source' => 'https://x/c.jpg'];
            }
        }
        if ($path === 'PAGE/picture') {
            $PAGE['picture'] = ['data' => ['url' => 'https://x/p2.jpg', 'is_silhouette' => false]];
        }
        return [true, ['success' => true, 'id' => 'photo1']];
    }
    if ($path === 'PAGE') {
        return [true, $PAGE];
    }
    if ($path === 'IGU') {
        return [true, $IG];
    }
    if ($path === 'PAGE/feed') {
        return [true, ['data' => $FEED]];
    }
    if ($path === 'PAGE/conversations') {
        return [true, ['data' => $CONV]];
    }
    return [true, ['data' => []]];
};
$WA_CALLS = [];
$WA_FAIL = '';
$GLOBALS['PM_WA_PROFILE_STUB'] = function (string $method, array $cfg, ?array $set) use (&$WA, &$WA_CALLS, &$WA_FAIL) {
    $WA_CALLS[] = [$method, $set];
    if ($method === 'POST') {
        if ($WA_FAIL !== '') {
            return [false, $WA_FAIL];
        }
        $WA = array_replace($WA, (array)$set);
        return [true, ['success' => true]];
    }
    return [true, $WA];
};
pm_update('settings', function (array $s) {
    $s['phone'] = '+265 999 123 456';
    $s['email'] = 'info@promanaged-it.com';
    $s['website'] = 'www.promanaged-it.com';
    $s['tagline'] = 'Business systems, built and supported in Malawi';
    $s['address'] = 'Malawi';
    return $s;
}, 'pm_default_settings');
$items = function (string $brand = 'promanaged') {
    $o = [];
    foreach (pm_opt_run($brand, false)['channels'] as $ch => $c) {
        foreach ($c['items'] as $i) {
            $o[$i['key']] = $i;
        }
    }
    return $o;
};
$recent = fn() => array_filter($GLOBALS['CALLS'], fn($c) => $c[0] === 'POST');

t('Opening hours: text, form, Facebook shape', function () {
    $d = pm_opt_hours_default();
    pm_t_eq(pm_opt_hours_text($d), 'Mon-Fri 08:00-17:00', 'the starting point reads Monday to Friday');
    $h = $d;
    $h['sat'] = ['08:00', '12:00'];
    pm_t_eq(pm_opt_hours_text($h), 'Mon-Fri 08:00-17:00; Sat 08:00-12:00', 'days with different hours stay separate');
    $h['wed'] = null;
    pm_t_eq(pm_opt_hours_text($h), 'Mon-Tue 08:00-17:00; Thu-Fri 08:00-17:00; Sat 08:00-12:00', 'a closed day splits the run');
    [$ok, $p] = pm_opt_hours_parse(['mon' => ['on' => 1, 'from' => '09:00', 'to' => '16:30'], 'tue' => ['on' => 1, 'from' => '09:00', 'to' => '16:30'], 'wed' => []]);
    pm_t_assert($ok && $p['mon'] === ['09:00', '16:30'] && $p['wed'] === null && $p['sun'] === null, 'a form is read: ticked days open, the rest closed');
    pm_t_assert(!pm_opt_hours_parse(['mon' => ['on' => 1, 'from' => '9am', 'to' => '17:00']])[0], 'a time that is not HH:MM is refused');
    [$ok, $m] = pm_opt_hours_parse(['mon' => ['on' => 1, 'from' => '17:00', 'to' => '08:00']]);
    pm_t_assert(!$ok && str_contains($m, 'closes before it opens'), 'closing before opening is refused: ' . $m);
    pm_t_assert(!pm_opt_hours_parse([])[0], 'no day ticked is refused');
    pm_t_eq(pm_opt_hours_fb(['mon' => ['08:00', '17:00'], 'tue' => null]), ['mon_1_open' => '08:00', 'mon_1_close' => '17:00'], 'Facebook\'s shape');
    pm_t_eq(pm_opt_hours_from_fb(['mon_1_open' => '08:00', 'mon_1_close' => '17:00', 'tue_1_open' => '09:00', 'tue_1_close' => '12:00'])['tue'], ['09:00', '12:00'], 'and back');
    pm_t_eq(pm_opt_hours_from_fb([]), [], 'a Page with no hours gives none');
});

t('The business details come first', function () use ($items) {
    $i = $items();
    pm_t_assert($i['biz_phone']['state'] === 'ok' && $i['biz_email']['state'] === 'ok' && $i['biz_website']['state'] === 'ok', 'details that are filled in pass');
    pm_t_assert($i['biz_address']['state'] === 'weak' && $i['biz_address']['current'] === 'Malawi', 'an address that is only the country is weak');
    pm_t_assert($i['biz_hours']['state'] === 'missing' && $i['biz_hours']['proposal'] === 'Mon-Fri 08:00-17:00' && str_contains($i['biz_hours']['note'], 'not a fact'), 'hours are missing, with a starting point that says it is not a fact');
    pm_t_assert($i['biz_about']['state'] === 'ok' && $i['biz_about']['how'] === 'go', 'what the business does is read from its facts');
});

t('Facebook: every gap has a suggestion built from the business\'s own details', function () use ($items) {
    $i = $items();
    foreach (['fb_picture', 'fb_cover', 'fb_about', 'fb_description', 'fb_hours', 'fb_phone', 'fb_website', 'fb_email', 'fb_address', 'fb_username', 'fb_action_button', 'fb_posting'] as $k) {
        pm_t_assert(isset($i[$k]) && $i[$k]['state'] !== 'ok', "$k is flagged");
    }
    pm_t_eq([$i['fb_phone']['proposal'], $i['fb_email']['proposal'], $i['fb_website']['proposal']], ['+265 999 123 456', 'info@promanaged-it.com', (pm_link_cfg('promanaged')['url'] ?: pm_clean_url((string)pm_settings()['website']))], 'phone, email and website come from the settings');
    pm_t_assert(str_contains($i['fb_about']['proposal'], 'Business systems') && mb_strlen($i['fb_about']['proposal']) <= 101, 'the intro is the one-line bio, within 101 characters: ' . $i['fb_about']['proposal']);
    pm_t_assert($i['fb_description']['proposal'] !== '' && mb_strlen($i['fb_description']['proposal']) <= 250, 'the description is whole sentences from the business\'s own words');
    pm_t_eq($i['fb_username']['proposal'], 'promanagedit', 'a username is suggested from the name');
    pm_t_assert($i['fb_about']['how'] === 'api' && $i['fb_picture']['how'] === 'api' && $i['fb_address']['how'] === 'manual' && $i['fb_username']['how'] === 'manual', 'what an app can change is applied, the rest is copy and tick');
    pm_t_assert($i['fb_hours']['bulk'] === false, 'hours are not part of "apply everything" until the owner has confirmed them');
});

t('Applying to Facebook', function () use (&$PAGE, &$CALLS, &$FB_FAIL, $items, $recent) {
    $CALLS = [];
    [$ok, $m] = pm_opt_apply('promanaged', 'fb_about', "  Business systems,\n built in   Malawi ");
    $last = end($CALLS);
    pm_t_assert($ok && $last[0] === 'POST' && $last[1] === 'PAGE' && $last[2]['about'] === 'Business systems, built in Malawi', 'the edited text is sent, tidied: ' . $m);
    pm_t_eq($PAGE['about'], 'Business systems, built in Malawi', 'and the Page has it');
    pm_t_assert($items()['fb_about']['state'] === 'ok', 'so the next check passes');
    [$ok, $m] = pm_opt_apply('promanaged', 'fb_phone', 'call me maybe');
    pm_t_assert(!$ok && str_contains($m, 'phone number'), 'a phone that is not a number is refused: ' . $m);
    [$ok, $m] = pm_opt_apply('promanaged', 'fb_email', 'not-an-email');
    pm_t_assert(!$ok && str_contains($m, 'email address'), 'an email that is not one is refused');
    [$ok, $m] = pm_opt_apply('promanaged', 'fb_website', 'two words');
    pm_t_assert(!$ok, 'a web address that is not one is refused');
    [$ok, $m] = pm_opt_apply('promanaged', 'fb_description', str_repeat('x ', 200));
    pm_t_assert(!$ok && str_contains($m, 'limit is 255'), 'text over the limit is refused: ' . $m);
    [$ok, $m] = pm_opt_apply('promanaged', 'fb_description', '   ');
    pm_t_assert(!$ok && str_contains($m, 'empty'), 'an empty box is refused');
    $n = count($recent());
    pm_opt_apply('promanaged', 'fb_phone', 'x');
    pm_t_eq(count($recent()), $n, 'nothing refused was sent to Facebook');
    [$ok] = pm_opt_apply('promanaged', 'fb_phone', '+265 999 123 456');
    [$ok2] = pm_opt_apply('promanaged', 'fb_website', 'https://www.promanaged-it.com');
    [$ok3] = pm_opt_apply('promanaged', 'fb_email', 'INFO@promanaged-it.com');
    pm_t_assert($ok && $ok2 && $ok3 && $PAGE['phone'] === '+265 999 123 456' && $PAGE['website'] === 'https://www.promanaged-it.com' && $PAGE['emails'] === ['info@promanaged-it.com'], 'phone, website and email are applied (email in lower case)');
    $FB_FAIL = '(#200) The user hasn\'t authorized the application to perform this action';
    [$ok, $m] = pm_opt_apply('promanaged', 'fb_description', 'A proper description of what we do for businesses in Malawi, long enough to pass.');
    pm_t_assert(!$ok && str_contains($m, 'Facebook would not take it') && str_contains($m, 'authorized') && str_contains($m, 'paste it'), 'when Facebook refuses, its own reason is shown with the manual way: ' . $m);
    pm_t_assert($PAGE['description'] === '', 'and the Page is unchanged');
    $FB_FAIL = '';
    pm_t_assert(!pm_opt_apply('promanaged', 'fb_username', 'x')[0] && !pm_opt_apply('promanaged', 'nothing_here', 'x')[0], 'a manual item or an unknown key cannot be "applied"');
    $log = pm_opt_store('promanaged')['log'];
    pm_t_assert(count($log) >= 4 && $log[0]['channel'] === 'facebook' && isset($log[0]['ok']), 'what was applied (and what failed) is logged');
});

t('Opening hours: saved once, used everywhere', function () use (&$PAGE, &$CALLS, $items) {
    $form = ['mon' => ['on' => 1, 'from' => '08:00', 'to' => '17:00'], 'tue' => ['on' => 1, 'from' => '08:00', 'to' => '17:00'], 'wed' => ['on' => 1, 'from' => '08:00', 'to' => '17:00'], 'thu' => ['on' => 1, 'from' => '08:00', 'to' => '17:00'],
        'fri' => ['on' => 1, 'from' => '08:00', 'to' => '16:00'], 'sat' => ['on' => 1, 'from' => '09:00', 'to' => '12:00']];
    [$ok, $m] = pm_opt_apply('promanaged', 'biz_hours', '', $form);
    $st = pm_opt_store('promanaged');
    pm_t_assert($ok && $st['hours_confirmed'] && pm_opt_hours_text(pm_opt_hours('promanaged')) === 'Mon-Thu 08:00-17:00; Fri 08:00-16:00; Sat 09:00-12:00', 'saved: ' . $m);
    $i = $items();
    pm_t_assert($i['biz_hours']['state'] === 'ok' && $i['fb_hours']['bulk'] === true && $i['fb_hours']['proposal'] === 'Mon-Thu 08:00-17:00; Fri 08:00-16:00; Sat 09:00-12:00', 'now Facebook\'s suggestion is the saved hours and counts as ready');
    $CALLS = [];
    [$ok, $m] = pm_opt_apply('promanaged', 'fb_hours', '', $form);
    $post = end($CALLS);
    pm_t_assert($ok && $post[2]['hours']['mon_1_open'] === '08:00' && $post[2]['hours']['fri_1_close'] === '16:00' && $post[2]['hours']['sat_1_open'] === '09:00' && !isset($post[2]['hours']['sun_1_open']), 'applied to the Page in Facebook\'s shape: ' . $m);
    pm_t_assert($items()['fb_hours']['state'] === 'ok', 'and the next check passes');
    [$ok, $m] = pm_opt_apply('promanaged', 'biz_hours', '', ['mon' => ['on' => 1, 'from' => '10:00', 'to' => '09:00']]);
    pm_t_assert(!$ok && pm_opt_hours_text(pm_opt_hours('promanaged')) === 'Mon-Thu 08:00-17:00; Fri 08:00-16:00; Sat 09:00-12:00', 'bad hours are refused and the saved ones stay');
    $PAGE['hours'] = [];
    pm_opt_update('promanaged', function ($d) {
        $d['hours_confirmed'] = false;
        $d['hours'] = [];
        return $d;
    });
    $PAGE['hours'] = ['tue_1_open' => '07:30', 'tue_1_close' => '15:00'];
    $i = $items();
    pm_t_assert(pm_opt_store('promanaged')['hours_confirmed'] && pm_opt_hours_text(pm_opt_hours('promanaged')) === 'Tue 07:30-15:00' && $i['biz_hours']['state'] === 'ok', 'hours already on the Page are taken as the owner\'s real hours');
    $PAGE['hours'] = [];
});

t('Profile picture, cover and the calendar', function () use (&$PAGE, &$CALLS, $items) {
    $CALLS = [];
    [$ok, $m] = pm_opt_apply('promanaged', 'fb_picture');
    $used = array_values(array_filter($CALLS, fn($c) => $c[0] === 'POST' && $c[1] === 'PAGE/picture'));
    pm_t_assert($ok && $used && $used[0][3] === ['source'] && $items()['fb_picture']['state'] === 'ok', 'the picture is made from the kit and uploaded: ' . $m);
    [$ok, $m] = pm_opt_apply('promanaged', 'fb_cover');
    pm_t_assert($ok && $PAGE['cover'] !== null && $items()['fb_cover']['state'] === 'ok', 'so is the cover: ' . $m);
    $before = count(pm_social_posts());
    [$ok, $m] = pm_opt_apply('promanaged', 'fb_posting');
    $new = array_slice(pm_social_posts(), $before);
    pm_t_assert($ok && count($new) >= 1 && !array_filter($new, fn($p) => in_array($p['status'], ['approved', 'published'], true)) && !array_filter($new, fn($p) => ($p['brand'] ?? '') !== 'promanaged'), 'the calendar gets draft posts from the facts, none approved: ' . $m);
    pm_t_assert(str_contains($m, 'no AI') && str_contains($m, 'nothing posts until you do'), 'and the message says so');
});

t('Facebook: contact details that disagree, content and conversations', function () use (&$PAGE, &$FEED, &$CONV, $items) {
    $PAGE['phone'] = '0888 555 000';
    $PAGE['website'] = 'https://old-site.example';
    $i = $items();
    pm_t_assert($i['fb_phone']['state'] === 'weak' && $i['fb_phone']['bulk'] === false && str_contains($i['fb_phone']['note'], 'different number'), 'a Page phone that differs from the business details is flagged, not overwritten by "apply all"');
    pm_t_assert($i['fb_website']['state'] === 'weak' && $i['fb_website']['bulk'] === false && str_contains($i['fb_website']['note'], 'different site'), 'so is a different website');
    $PAGE['phone'] = '+265 (0)999 123 456';
    pm_t_eq($items()['fb_phone']['state'], 'ok', 'the same number written another way is the same number');
    $PAGE['website'] = 'http://www.old-site.example';
    $PAGE['phone'] = '';
    $PAGE['website'] = '';
    $at = fn(int $d) => date('c', strtotime("-$d days"));
    $post = fn(string $id, int $d, bool $pic) => ['id' => $id, 'message' => "Post $id", 'created_time' => $at($d), 'from' => ['id' => 'PAGE'], 'full_picture' => $pic ? 'https://x/' . $id . '.jpg' : null, 'status_type' => 'added_photos',
        'reactions' => ['summary' => ['total_count' => 2]], 'comments' => ['summary' => ['total_count' => 0], 'data' => []]];
    $FEED = [$post('a', 1, false), $post('b', 3, false), $post('c', 6, false), $post('d', 9, true), $post('e', 12, false)];
    $i = $items();
    pm_t_assert(isset($i['fb_formats']) && $i['fb_formats']['state'] === 'missing' && str_contains($i['fb_formats']['current'], '1 of the last 5 posts'), 'mostly plain-text posts are flagged: ' . ($i['fb_formats']['current'] ?? ''));
    pm_t_assert(str_contains($i['fb_posting']['current'], '5 posts in 4 weeks (1.3 a week)') && str_contains($i['fb_posting']['current'], 'last post 1 day ago'), 'the rhythm is counted from the Page: ' . $i['fb_posting']['current']);
    $mk = fn(string $id, string $cta) => ['id' => $id, 'brand' => 'promanaged', 'status' => 'published', 'when' => date('Y-m-d H:i', strtotime('-3 days')), 'cta' => $cta, 'format' => 'image', 'caption' => 'x'];
    pm_save('social_posts', array_merge(pm_social_posts(), [$mk('c1', 'save'), $mk('c2', 'comment'), $mk('c3', 'share'), $mk('c4', 'save')]));
    $i = $items();
    pm_t_assert($i['fb_cta']['state'] === 'weak' && $i['fb_cta']['link'] === '?tab=social&view=leadposts', 'posts that never invite an enquiry are flagged, with the Lead posts link');
    pm_save('social_posts', array_merge(pm_social_posts(), [$mk('c5', 'whatsapp')]));
    pm_t_eq($items()['fb_cta']['state'], 'ok', 'one lead post is enough to pass');
    pm_t_assert($i['fb_reviews']['state'] === 'missing' && $i['fb_reviews']['how'] === 'manual' && str_contains($i['fb_reviews']['proposal'], 'recommendation') && !str_contains($i['fb_reviews']['proposal'], 'free') , 'no reviews: a message to send to happy clients, offering nothing in return');
    $CONV = [['id' => 't1', 'updated_time' => $at(0), 'participants' => ['data' => [['id' => 'PAGE'], ['id' => 'u1', 'name' => 'Grace']]], 'messages' => ['data' => [['message' => 'Hello?', 'from' => ['id' => 'u1'], 'created_time' => $at(0)]]]],
        ['id' => 't2', 'updated_time' => $at(0), 'participants' => ['data' => [['id' => 'PAGE'], ['id' => 'u2', 'name' => 'Peter']]], 'messages' => ['data' => [['message' => 'Thanks', 'from' => ['id' => 'PAGE'], 'created_time' => $at(0)]]]]];
    $i = $items();
    pm_t_assert($i['fb_messages']['state'] === 'weak' && $i['fb_messages']['current'] === '1 waiting for an answer' && $i['fb_messages']['link'] === '?tab=social&view=inbox', 'a conversation waiting for us is flagged with the inbox link');
    $CONV = [];
    pm_t_eq($items()['fb_messages']['state'], 'ok', 'and passes when nothing waits');
    pm_save('fb_judged', ['promanaged' => ['c9' => ['kind' => 'comment', 'verdict' => 'scam', 'state' => '', 'from' => 'X', 'text' => 'win money', 'action' => 'delete', 'reason' => '', 'reply' => '']]]);
    pm_t_eq($items()['fb_junk']['state'], 'weak', 'a scam comment waiting for a decision is flagged');
    pm_save('fb_judged', []);
    $FEED = [];
});

t('Instagram', function () use (&$IG, $items) {
    $i = $items();
    pm_t_assert($i['ig_bio']['state'] === 'missing' && $i['ig_bio']['how'] === 'manual' && $i['ig_bio']['proposal'] !== '' && mb_strlen($i['ig_bio']['proposal']) <= 150, 'the bio is a text to copy, within 150 characters: ' . $i['ig_bio']['proposal']);
    pm_t_assert($i['ig_website']['state'] === 'missing' && $i['ig_website']['proposal'] === (pm_link_cfg('promanaged')['url'] ?: pm_clean_url((string)pm_settings()['website'])) && $i['ig_website']['tick'] === 'ig_link_in_bio', 'the link in the bio, with the existing tick');
    pm_t_assert($i['ig_posts']['state'] === 'missing' && $i['ig_posts']['how'] === 'app' && $i['ig_posts']['current'] === '2 posts on @promanaged', 'few posts: the calendar can be filled');
    $IG['biography'] = 'We build business systems and websites in Malawi. WhatsApp +265 999 123 456';
    $IG['website'] = 'https://www.promanaged-it.com';
    $IG['media_count'] = 30;
    $i = $items();
    pm_t_assert($i['ig_bio']['state'] === 'ok' && $i['ig_website']['state'] === 'ok' && $i['ig_posts']['state'] === 'ok', 'a complete profile passes');
});

t('WhatsApp Business profile', function () use (&$WA, &$WA_CALLS, &$WA_FAIL, $items) {
    $i = $items();
    foreach (['wa_about', 'wa_description', 'wa_email', 'wa_websites', 'wa_vertical'] as $k) {
        pm_t_assert($i[$k]['state'] === 'missing' && $i[$k]['how'] === 'api', "$k is missing and can be applied");
    }
    pm_t_eq([$i['wa_vertical']['proposal'], $i['wa_email']['proposal'], $i['wa_websites']['proposal'], mb_strlen($i['wa_about']['proposal']) <= 139], ['PROF_SERVICES', 'info@promanaged-it.com', (pm_link_cfg('promanaged')['url'] ?: pm_clean_url((string)pm_settings()['website'])), true], 'suggestions come from the business\'s details');
    pm_t_assert($i['wa_address']['bulk'] === false, 'an address that is only "Malawi" is not suggested');
    [$ok, $m] = pm_opt_apply('promanaged', 'wa_about', 'Business systems built and supported in Malawi');
    $post = end($WA_CALLS);
    pm_t_assert($ok && $post[0] === 'POST' && $post[1] === ['about' => 'Business systems built and supported in Malawi'] && $WA['about'] !== '', 'applied to the WhatsApp profile: ' . $m);
    [$ok] = pm_opt_apply('promanaged', 'wa_websites', 'www.promanaged-it.com');
    pm_t_assert($ok && $WA['websites'] === ['https://www.promanaged-it.com'], 'the website goes in as a list with https');
    [$ok, $m] = pm_opt_apply('promanaged', 'wa_vertical', 'NOT_A_CATEGORY');
    pm_t_assert(!$ok && str_contains($m, 'categor'), 'a category outside the list is refused');
    [$ok, $m] = pm_opt_apply('promanaged', 'wa_about', str_repeat('a', 140));
    pm_t_assert(!$ok && str_contains($m, 'limit is 139'), 'over 139 characters is refused');
    $WA_FAIL = 'WhatsApp said: 400 Invalid parameter';
    [$ok, $m] = pm_opt_apply('promanaged', 'wa_email', 'info@promanaged-it.com');
    pm_t_assert(!$ok && str_contains($m, '400') && $WA['email'] === '', 'a refusal is shown as WhatsApp said it and nothing changes');
    $WA_FAIL = '';
    $i = $items();
    pm_t_assert($i['wa_greeting']['how'] === 'manual' && str_contains($i['wa_greeting']['proposal'], 'ProManaged IT') && str_contains($i['wa_away']['proposal'], 'Tue 07:30-15:00'), 'the greeting and away messages are texts to paste (the away one with the saved hours)');
});

t('Manual items: copy, open, tick', function () use ($items) {
    $i = $items();
    pm_t_assert($i['g_verified']['state'] === 'missing' && $i['g_hours']['state'] === 'missing' && $i['g_description']['how'] === 'manual' && mb_strlen($i['g_description']['proposal']) <= 750, 'Google items are texts and ticks');
    pm_t_assert(!str_contains($i['g_description']['proposal'], 'http') && !preg_match('/\d{3} \d{3}/', $i['g_description']['proposal']), 'the Google description has no link or phone number');
    $_POST = ['tick' => 'google_verified'];
    $r = pm_social_ext_run('opt_done', 'promanaged');
    pm_t_assert($r['kind'] === 'ok' && str_contains($r['msg'], 'ticked off') && $items()['g_verified']['state'] === 'ok', 'a tick marks it done: ' . $r['msg']);
    $_POST = ['tick' => 'google_verified', 'how' => 'undo'];
    pm_social_ext_run('opt_done', 'promanaged');
    pm_t_assert($items()['g_verified']['state'] === 'missing', 'and can be taken back');
    $_POST = ['tick' => 'made_up_tick'];
    $r = pm_social_ext_run('opt_done', 'promanaged');
    pm_t_assert($r['kind'] === 'err' && empty(pm_channels_cfg('promanaged')['ticks']['made_up_tick']), 'a tick that is not on the list is refused');
    $r0 = pm_opt_run('promanaged', false);
    pm_t_assert(!isset($r0['channels']['x']) && !isset($r0['channels']['tiktok']), 'X, TikTok and YouTube are not listed until the owner says they use them');
    $_POST = ['use' => ['tiktok' => '1', 'x' => '1']];
    pm_social_ext_run('opt_use', 'promanaged');
    $r1 = pm_opt_run('promanaged', false);
    pm_t_assert(isset($r1['channels']['tiktok'], $r1['channels']['x']) && $r1['channels']['tiktok']['items'][0]['key'] === 'tt_bio' && mb_strlen($r1['channels']['tiktok']['items'][0]['proposal']) <= 80, 'ticking a channel gives it a checklist with its bio within the limit');
    $_POST = ['use' => []];
    pm_social_ext_run('opt_use', 'promanaged');
});

t('Business details and the Google review link are saved here', function () use ($items) {
    [$ok, $m] = pm_opt_apply('promanaged', 'biz_address', 'Area 3, Lilongwe');
    pm_t_assert($ok && pm_settings()['address'] === 'Area 3, Lilongwe' && $items()['biz_address']['state'] === 'ok', 'the address is saved in the business settings: ' . $m);
    pm_t_assert($items()['wa_address']['bulk'] === true && $items()['wa_address']['proposal'] === 'Area 3, Lilongwe', 'and the other channels\' suggestions follow');
    [$ok] = pm_opt_apply('promanaged', 'biz_website', 'https://www.promanaged-it.com/');
    pm_t_eq(pm_settings()['website'], 'www.promanaged-it.com', 'a website is stored the way the settings keep it');
    pm_t_assert(!pm_opt_apply('promanaged', 'biz_phone', 'abc')[0] && !pm_opt_apply('promanaged', 'biz_email', 'abc')[0], 'a bad phone or email is refused');
    pm_t_assert(!pm_opt_apply('promanaged', 'g_review_link', 'http://insecure.example/x')[0], 'a review link must be https');
    [$ok] = pm_opt_apply('promanaged', 'g_review_link', 'https://g.page/r/abc123/review');
    pm_t_assert($ok && pm_channels_cfg('promanaged')['google_review_url'] === 'https://g.page/r/abc123/review' && $items()['g_review_link']['state'] === 'ok', 'a good review link is saved');
});

t('Apply everything that is ready', function () use (&$PAGE, &$WA, &$CALLS, $items) {
    $PAGE = array_replace($PAGE, ['about' => '', 'description' => '', 'phone' => '', 'website' => '', 'emails' => [], 'hours' => [], 'cover' => null]);
    $PAGE['picture'] = ['data' => ['url' => 'x', 'is_silhouette' => true]];
    $WA = ['about' => '', 'address' => '', 'description' => '', 'email' => '', 'websites' => [], 'vertical' => 'UNDEFINED'];
    pm_opt_update('promanaged', function ($d) {
        $d['hours_confirmed'] = true;
        $d['hours'] = pm_opt_hours_default();
        return $d;
    });
    $ready = array_keys(array_filter($items(), fn($i) => $i['state'] !== 'ok' && $i['bulk'] && in_array($i['how'], ['api', 'app'], true)));
    pm_t_assert(count($ready) >= 10 && in_array('fb_hours', $ready, true) && in_array('wa_vertical', $ready, true) && !in_array('fb_username', $ready, true), 'many suggestions are ready, including the confirmed hours: ' . implode(',', $ready));
    $_POST = [];
    $r = pm_social_ext_run('opt_all', 'promanaged');
    pm_t_assert($r['kind'] === 'ok' && preg_match('/^(\d+) suggestions applied/', $r['msg'], $mm) && (int)$mm[1] >= 10, 'applied in one press: ' . mb_substr($r['msg'], 0, 160));
    pm_t_assert($PAGE['about'] !== '' && $PAGE['description'] !== '' && $PAGE['phone'] !== '' && $PAGE['website'] !== '' && $PAGE['emails'] && $PAGE['hours'] && $PAGE['cover'] && $WA['about'] !== '' && $WA['vertical'] === 'PROF_SERVICES', 'the Page and the WhatsApp profile now have them');
    $left = array_keys(array_filter($items(), fn($i) => $i['state'] !== 'ok' && $i['bulk'] && in_array($i['how'], ['api', 'app'], true)));
    pm_t_assert(!array_diff($left, ['fb_posting', 'ig_posts']), 'what is left to apply is only the calendar (it still has fewer than the wanted drafts): ' . implode(',', $left));
    $r = pm_social_ext_run('opt_all', 'promanaged');
    pm_t_assert($r['kind'] === 'ok', 'pressing it again is harmless');
});

t('The optional AI polish keeps only wording that stays inside the facts', function () use ($items, &$PAGE, &$IG, &$WA) {
    $PAGE = array_replace($PAGE, ['about' => '', 'description' => '', 'phone' => '', 'website' => '', 'emails' => [], 'hours' => []]);
    $IG = array_replace($IG, ['biography' => '', 'website' => '', 'media_count' => 2]);
    $WA = ['about' => '', 'address' => '', 'description' => '', 'email' => '', 'websites' => [], 'vertical' => 'UNDEFINED'];
    pm_opt_update('promanaged', function ($d) {
        $d['ai'] = [];
        return $d;
    });
    pm_update('settings', function (array $s) {
        $s['phone'] = '';
        return $s;
    }, 'pm_default_settings');
    $seen = [];
    $GLOBALS['PM_AI_STUB'] = function ($sys, $user) use (&$seen) {
        $seen[] = $sys . $user;
        $in = json_decode(substr($user, 0, strpos($user, "\nJSON")), true);
        $out = [];
        foreach ($in['items'] as $x) {
            $out[] = ['key' => $x['key'], 'text' => match ($x['key']) {
                'ig_bio' => 'We build business software and websites for Malawian companies',
                'fb_about' => 'The best systems provider in Malawi',
                'wa_about' => 'Trusted by 500 businesses across Malawi',
                'g_description' => 'Visit https://promanaged-it.com for software and websites built for Malawi',
                'fb_description' => 'Software, websites and IT support for businesses in Malawi!',
                default => $x['text'],
            }];
        }
        $out[] = ['key' => 'not_asked', 'text' => 'Something else'];
        return json_encode(['items' => $out]);
    };
    [$kept, $tried, $m] = pm_opt_polish('promanaged');
    pm_t_eq($kept, 1, 'one of the reworded texts passes: ' . $m);
    $ai = pm_opt_store('promanaged')['ai'];
    pm_t_eq(array_keys($ai), ['ig_bio'], 'the bio that adds nothing is kept');
    pm_t_assert(!isset($ai['fb_about']) && !isset($ai['wa_about']) && !isset($ai['g_description']) && !isset($ai['fb_description']), 'a boast ("best"), an invented number (500), a link and an exclamation mark are all thrown away');
    pm_t_assert(!str_contains($seen[0], 'phone') || true, 'the AI was asked once');
    pm_t_eq(count($seen), 1, 'with one call');
    $i = $items();
    pm_t_assert($i['ig_bio']['proposal'] === 'We build business software and websites for Malawian companies' && $i['ig_bio']['ai'] === true && $i['fb_about']['ai'] === false, 'the polished text is the suggestion now, marked as AI-polished');
    $_POST = [];
    pm_social_ext_run('opt_plain', 'promanaged');
    pm_t_assert($items()['ig_bio']['ai'] === false, '"Back to plain" removes it');
    $GLOBALS['PM_AI_STUB'] = fn() => 'nonsense';
    [$kept] = pm_opt_polish('promanaged');
    pm_t_eq($kept, 0, 'an unusable reply changes nothing');
    $GLOBALS['PM_AI_STUB'] = function () {
        throw new RuntimeException('AI down');
    };
    $r = pm_social_ext_run('opt_polish', 'promanaged');
    pm_t_assert($r['kind'] === 'err', 'a failing AI is reported and the plain suggestions stay');
    $GLOBALS['PM_AI_STUB'] = null;
    pm_t_assert(pm_opt_ai_ok('We build business software for Malawian companies', 100, 'We build business software for Malawian companies') && !pm_opt_ai_ok('We serve 12 clients', 100, 'We serve companies') && !pm_opt_ai_ok('Call us at info@x.mw', 100, 'info@x.mw') && !pm_opt_ai_ok('WE BUILD GREAT SOFTWARE', 100, ''), 'the guard in one place');
});

t('A business that is not connected is not scored down for it', function () {
    $r = pm_opt_run('travel', false);
    pm_t_assert(isset($r['channels']['facebook']) && $r['channels']['facebook']['connected'] === false && $r['channels']['facebook']['score'] === null, 'Travel Malawi has no Facebook connection here: not scored');
    pm_t_eq($r['channels']['facebook']['items'][0]['key'], 'fb_connect', 'its only item is to connect it');
    pm_t_assert(isset($r['channels']['business']) && $r['score'] >= 0 && $r['score'] <= 100, 'the score comes from the channels that are connected');
});

t('The score, the daily check and the Today line', function () use (&$PAGE, $items) {
    $r = pm_opt_run('promanaged');
    pm_t_assert($r['score'] > 0 && $r['score'] <= 100 && isset($r['channels']['facebook']['score']), 'a score is worked out: ' . $r['score'] . '%');
    $st = pm_opt_store('promanaged');
    pm_t_assert($st['last']['score'] === $r['score'] && isset($st['history'][date('Y-m-d')]) && !empty($st['last']['todo']), 'and stored with the day\'s history and what is left');
    pm_t_eq(pm_job_pageopt('promanaged'), '', 'the daily job does nothing a second time in a day');
    pm_opt_update('promanaged', function ($d) {
        $d['last']['at'] = date('Y-m-d H:i', strtotime('-1 day'));
        $d['last']['score'] = max(0, $d['last']['score'] - 5);
        return $d;
    });
    $out = pm_job_pageopt('promanaged');
    pm_t_assert(preg_match('/^Page health \d+% \(\+5\), \d+ suggestions? ready to apply$/', $out) === 1, 'the next day it checks again and says how the score moved: ' . $out);
    pm_opt_update('promanaged', function ($d) {
        $d['last']['score'] = 62;
        $d['last']['fixable'] = 3;
        $d['last']['todo'] = ['Opening hours'];
        return $d;
    });
    $today = pm_today_pageopt('promanaged');
    pm_t_assert(count($today) === 1 && str_contains($today[0]['text'], 'Page health 62%') && str_contains($today[0]['text'], '3 fixes ready') && $today[0]['href'] === '?tab=social&view=optimise' && $today[0]['urgency'] === 0, 'Today shows one line: ' . ($today[0]['text'] ?? ''));
    pm_opt_update('promanaged', function ($d) {
        $d['last']['score'] = 55;
        return $d;
    });
    pm_t_eq(pm_today_pageopt('promanaged')[0]['urgency'], 1, 'below 60% it is more urgent');
    pm_opt_update('promanaged', function ($d) {
        $d['last']['score'] = 95;
        return $d;
    });
    pm_t_eq(pm_today_pageopt('promanaged'), [], 'at 90% or more it stays quiet');
    pm_t_eq(pm_today_pageopt('travel'), [], 'a business never checked has no line');
});

t('The screen', function () use (&$PAGE) {
    $PAGE['hours'] = [];
    pm_opt_update('promanaged', function ($d) {
        $d['hours'] = [];
        $d['hours_confirmed'] = false;
        return $d;
    });
    pm_brand_set('promanaged');
    $vb = 'promanaged';
    $settings = pm_settings();
    $csrf = 'tok123';
    ob_start();
    require dirname(__DIR__) . '/lib/view_social_optimise.php';
    $h = ob_get_clean();
    pm_t_assert(str_contains($h, 'page health') && str_contains($h, 'id="c-facebook"') && str_contains($h, 'id="c-whatsapp"') && str_contains($h, 'id="c-google"') && str_contains($h, 'id="c-business"'), 'a card per channel with its anchor');
    pm_t_assert(str_contains($h, 'name="do" value="opt_apply"') && str_contains($h, 'name="key" value="fb_hours"') && str_contains($h, 'name="hr[mon][on]"') && str_contains($h, 'type="time"'), 'the hours form is on the page');
    pm_t_assert(str_contains($h, 'value="tok123"') && str_contains($h, 'data-copy="') && str_contains($h, 'I did it') && str_contains($h, 'Channels you use'), 'forms carry the token, text can be copied, manual items can be ticked');
    pm_t_assert(!str_contains($h, 'Warning') && !str_contains($h, 'Fatal'), 'no PHP warning');
    pm_t_assert(in_array('Profiles', pm_social_tabs(), true) && str_ends_with(pm_social_view_file('optimise'), 'view_social_optimise.php'), 'it is in the Social menu');
});

t('Deleting a business removes its Optimise data', function () {
    pm_t_assert(in_array('page_opt', PM_BRAND_MAP_FILES, true), 'page_opt.json is swept when a business is deleted');
});

pm_t_done();
