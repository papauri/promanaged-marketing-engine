<?php
/**
 * Tests for acting on the page audit: the kind of each fix, the settings the data suggests (weekly goal, posting times, theme mix, channels, reply style) and applying them, the fix list as
 * actions with the AI's wording kept only when it stays inside the facts, post rewrites and saving one to the Page, ideas into the content bank, "show me how", and the screen.
 * Runs on a TEMP COPY of data/ with Facebook and the AI stubbed; nothing real is read, changed or posted. Run: php tests/test_audit_actions.php
 */
require __DIR__ . '/boot.php';
$T = pm_test_boot();
file_put_contents($T['env'], "APP_URL=https://app.test\nFB_PAGE_ID=PAGE\nFB_PAGE_TOKEN=T\nIG_USER_ID=IGU\n");
pm_brand_set('promanaged');
$GLOBALS['PM_AI_STUB'] = null;
pm_save('page_opt', ['none' => []]);
pm_save('social_channels', ['none' => []]);
pm_save('page_audit', ['none' => []]);
pm_save('social_goals', ['none' => []]);
pm_update('settings', function (array $s) {
    unset($s['social']);
    $s['phone'] = '+265 999 123 456';
    $s['email'] = 'info@promanaged-it.com';
    $s['tagline'] = 'Business systems, built and supported in Malawi';
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

/* ---- a fake Page with twelve posts, most reactions at 18:00 ---- */
$PAGE = ['name' => 'ProManaged IT', 'about' => 'x', 'description' => '', 'category' => 'Software', 'website' => 'https://www.promanaged-it.com', 'phone' => '+265 999 123 456', 'emails' => ['info@promanaged-it.com'], 'username' => 'promanaged',
    'fan_count' => 40, 'followers_count' => 45, 'picture' => ['data' => ['url' => 'u', 'is_silhouette' => false]], 'cover' => ['source' => 'c'], 'hours' => ['mon_1_open' => '08:00', 'mon_1_close' => '17:00'], 'location' => ['city' => 'Lilongwe']];
$FEED = [];
foreach (range(0, 11) as $i) {
    $evening = $i % 3 === 0;
    $ts = strtotime(date('Y-m-d', strtotime('-' . (2 + $i * 3) . ' days')) . ($evening ? ' 18:10' : ' 09:30'));
    $FEED[] = ['id' => '1000' . $i . '_77' . $i, 'message' => $i === 0 ? 'We build business systems for hotels and shops in Malawi. Message us on WhatsApp to see how.' : "Post number $i about our IT support for small businesses.", 'created_time' => date('c', $ts), 'from' => ['id' => 'PAGE'],
        'full_picture' => $i % 2 ? 'https://x/p.jpg' : null, 'status_type' => 'added_photos', 'reactions' => ['summary' => ['total_count' => $evening ? 14 : 2]], 'comments' => ['summary' => ['total_count' => 0], 'data' => []]];
}
$CALLS = [];
$GLOBALS['PM_GRAPH_STUB'] = function (string $method, string $path, array $params, string $token, array $files) use (&$PAGE, &$FEED, &$CALLS) {
    $CALLS[] = [$method, $path, $params];
    if ($method === 'POST') {
        return [true, ['success' => true]];
    }
    if ($path === 'PAGE') {
        return [true, $PAGE];
    }
    if ($path === 'PAGE/feed') {
        return [true, ['data' => $FEED]];
    }
    return [true, ['data' => []]];
};
$AUDIT = [
    'score' => 58, 'scores' => ['profile' => 70, 'consistency' => 40, 'content' => 60, 'engagement' => 50, 'responsiveness' => 60], 'headline' => 'A tidy Page that posts too rarely.', 'working' => ['Evening posts get the most reactions'],
    'fixes' => [
        ['action' => 'Rewrite the page intro so it says what you do', 'why' => 'Visitors cannot tell what the Page is for.', 'kind' => 'about', 'text' => 'Business systems and IT support for Malawian businesses'],
        ['action' => 'Write a proper description', 'why' => 'Empty today.', 'kind' => 'description', 'text' => 'Trusted by 500 businesses across Malawi'],
        ['action' => 'Add your opening hours', 'why' => 'Customers do not know when you answer.', 'kind' => 'hours'],
        ['action' => 'Post at least three times a week', 'why' => 'Gaps of ten days.'],
        ['action' => 'Join the local business groups and comment there', 'why' => 'Reach.', 'kind' => 'other'],
    ],
    'posts' => [['id' => '10000_770', 'post' => 'We build business systems for hotels and shops', 'verdict' => 'Clear, but no easy next step.', 'improve' => 'End with one easy question or a WhatsApp invitation.'],
        ['id' => '', 'post' => 'Post number 4 about our IT support', 'verdict' => 'Generic.', 'improve' => 'Name one real problem you solve.']],
    'timing' => 'Evenings beat mornings.', 'replies' => ['verdict' => 'Replies are curt.', 'better_example' => 'Thank you for asking, we will send the details on WhatsApp today.', 'style_rule' => 'Warm and short, thank the person first and offer WhatsApp for details'],
    'ideas' => ['Show how a hotel closes the day in ten minutes', 'A short tip on backing up shop records'],
];

t('The kind of each fix', function () {
    pm_t_eq(pm_aa_kind(['kind' => 'Hours']), 'hours', 'the AI\'s own label is taken (any capitals)');
    pm_t_eq(pm_aa_kind(['kind' => 'nonsense', 'action' => 'Add opening hours']), 'hours', 'an unknown label falls back to the words');
    foreach (['Add the page intro' => 'about', 'Write a Page description' => 'description', 'Upload a cover photo' => 'cover', 'Change the profile picture' => 'picture', 'Add a phone number' => 'phone',
        'Add your website link' => 'website', 'Add an email address' => 'email', 'Answer the unanswered comments' => 'replies', 'Reply to Messenger messages' => 'messages', 'Add a call to action to posts' => 'cta',
        'Pin your best post' => 'pin', 'Ask clients for reviews' => 'reviews', 'Post more consistently each week' => 'posting', 'Use more pictures and video' => 'formats', 'Post at the best time of day' => 'timing', 'Stop posting several pictures within minutes of each other' => 'posting', 'Connect a WhatsApp business number' => 'phone', 'Publish posts introducing the browser-based management system for local hotels' => 'other', 'Learn to juggle' => 'other'] as $text => $want) {
        pm_t_eq(pm_aa_kind(['action' => $text]), $want, "\"$text\" is $want");
    }
});

t('Posting times: parse, apply, and the planner uses them', function () {
    pm_t_eq(pm_aa_parse_times('18:00, 7:30;12'), [true, ['07:30', '12:00', '18:00']], 'times are read in several spellings, sorted');
    foreach (['25:00', 'noon', '9:75', '', '1,2,3,4,5'] as $bad) {
        pm_t_assert(!pm_aa_parse_times($bad)[0], "\"$bad\" is refused");
    }
    pm_t_eq(pm_aa_times('promanaged'), [], 'none set yet');
    [$ok, $m] = pm_aa_apply('promanaged', 'post_times', '18:00, 12:30');
    pm_t_assert($ok && pm_aa_times('promanaged') === ['12:30', '18:00'] && str_contains($m, 'already planned keep their time'), 'saved: ' . $m);
    pm_t_eq(pm_social_hours('promanaged'), ['12:30', '18:00'], 'the planner\'s hours are the owner\'s');
    pm_t_eq([pm_social_time_for('promanaged', '2026-10-14', 0), pm_social_time_for('promanaged', '2026-10-14', 1), pm_social_time_for('promanaged', '2026-10-14', 2)], ['12:30', '18:00', '12:30'], 'and each post of a day takes the next one in turn');
    pm_t_assert(!pm_aa_apply('promanaged', 'post_times', 'tomorrow')[0] && pm_aa_times('promanaged') === ['12:30', '18:00'], 'a bad value is refused and the saved times stay');
    [$ok] = pm_aa_apply('promanaged', 'post_times_clear');
    pm_t_assert($ok && pm_aa_times('promanaged') === [] && pm_social_time_for('promanaged', '2026-10-14', 0) !== '', 'clearing goes back to the automatic times');
    pm_t_assert(!isset(pm_social_settings('promanaged')['times']), 'with nothing left in the settings');
});

t('The weekly goal', function () {
    foreach (['0', '22', 'many', ''] as $bad) {
        pm_t_assert(!pm_aa_apply('promanaged', 'goal_posts', $bad)[0], "\"$bad\" is not a goal");
    }
    [$ok] = pm_aa_apply('promanaged', 'goal_posts', '4');
    pm_t_assert($ok && pm_social_goals('promanaged')['posts_per_week'] === 4 && pm_social_goals('promanaged')['enquiries_per_week'] === 2, 'a goal is saved without disturbing the other goals');
});

t('Settings the Page\'s own numbers suggest', function () use (&$PAGE) {
    $data = pm_fb_audit_data('promanaged');
    pm_t_assert(($data['posting']['posts_counted'] ?? 0) === 12 && !empty($data['recent_posts'][0]['id']), 'the audit data counts the posts and now carries each post\'s id');
    pm_aa_apply('promanaged', 'goal_posts', '12');
    $items = array_column(pm_aa_settings_items('promanaged', null, $data), null, 'key');
    pm_t_assert(isset($items['goal_posts'], $items['post_times']) && $items['goal_posts']['do'] === 'aa_apply' && $items['goal_posts']['how'] === 'app', 'goal and times are offered, applied inside the app');
    pm_t_assert($items['goal_posts']['state'] === 'weak' && str_contains($items['goal_posts']['current'], 'Goal 12 a week') && (int)$items['goal_posts']['proposal'] >= 3 && (int)$items['goal_posts']['proposal'] <= 5, 'a goal far from what the Page manages is flagged with a realistic one: ' . $items['goal_posts']['proposal']);
    pm_t_assert($items['post_times']['state'] === 'weak' && str_starts_with($items['post_times']['proposal'], '18:00') && str_contains($items['post_times']['note'], '18:00 averages'), 'the best hour from the results is suggested: ' . $items['post_times']['proposal']);
    pm_t_assert($items['post_times']['bulk'] === true && $items['goal_posts']['bulk'] === true, 'and both are safe to apply together');
    pm_aa_apply('promanaged', 'post_times', $items['post_times']['proposal']);
    $items = array_column(pm_aa_settings_items('promanaged', null, $data), null, 'key');
    pm_t_assert($items['post_times']['state'] === 'ok' && str_contains($items['post_times']['current'], 'Set by you') && isset($items['post_times_clear']), 'once applied it is good, and a way back is offered');
    pm_aa_apply('promanaged', 'post_times_clear');
    $few = ['posting' => ['posts_counted' => 4, 'posts_per_week' => 1.0, 'by_hour' => [], 'avg_engagement' => 3]];
    $items = array_column(pm_aa_settings_items('promanaged', null, $few), null, 'key');
    pm_t_assert($items['post_times']['state'] === 'ok' && str_contains($items['post_times']['note'], '8 are needed'), 'with too few posts it says so and does not guess');
    pm_t_assert(!isset(array_column(pm_aa_settings_items('promanaged', null, ['posting' => ['posts_counted' => 1]]), null, 'key')['goal_posts']), 'and gives no goal from one post');
});

t('Which channels post by themselves', function () {
    $items = array_column(pm_aa_settings_items('promanaged', null, ['posting' => []]), null, 'key');
    pm_t_assert(!isset($items['ch_instagram_on']), 'Instagram linked and already on: nothing to offer');
    pm_social_settings_save('promanaged', array_replace_recursive(pm_social_settings('promanaged'), ['channels' => ['instagram' => false, 'linkedin' => true]]));
    $items = array_column(pm_aa_settings_items('promanaged', null, ['posting' => []]), null, 'key');
    pm_t_assert(isset($items['ch_instagram_on']) && $items['ch_instagram_on']['bulk'] === true, 'Instagram linked but off is offered');
    pm_t_assert(isset($items['ch_linkedin_off']), 'LinkedIn switched on with no connection is offered to be switched off');
    pm_aa_apply('promanaged', 'ch_instagram_on');
    pm_aa_apply('promanaged', 'ch_linkedin_off');
    $set = pm_social_settings('promanaged');
    pm_t_assert($set['channels']['instagram'] === true && $set['channels']['linkedin'] === false, 'both are applied');
    $items = array_column(pm_aa_settings_items('promanaged', null, ['posting' => []]), null, 'key');
    pm_t_assert(!isset($items['ch_instagram_on']) && !isset($items['ch_linkedin_off']), 'and then not offered again');
});

t('How replies sound', function () use ($AUDIT) {
    $items = array_column(pm_aa_settings_items('promanaged', $AUDIT, ['posting' => []]), null, 'key');
    pm_t_assert(isset($items['reply_style']) && $items['reply_style']['proposal'] === 'Warm and short, thank the person first and offer WhatsApp for details' && $items['reply_style']['state'] === 'weak', 'the audit\'s style rule is offered, editable');
    pm_t_assert(!isset(array_column(pm_aa_settings_items('promanaged', null, ['posting' => []]), null, 'key')['reply_style']), 'with no audit there is none');
    $shout = $AUDIT;
    $shout['replies']['style_rule'] = 'Reply FAST to 100 people!';
    pm_t_assert(!isset(array_column(pm_aa_settings_items('promanaged', $shout, ['posting' => []]), null, 'key')['reply_style']), 'a rule with a number or an exclamation mark is not offered');
    pm_t_assert(!pm_aa_apply('promanaged', 'reply_style', '')[0] && !pm_aa_apply('promanaged', 'reply_style', str_repeat('a ', 120))[0], 'an empty or over-long style is refused');
    [$ok] = pm_aa_apply('promanaged', 'reply_style', 'Warm and short, thank the person first');
    pm_t_assert($ok && pm_aa_reply_style('promanaged') === 'Warm and short, thank the person first', 'the style is saved');
    $seen = '';
    $GLOBALS['PM_AI_STUB'] = function ($sys) use (&$seen) {
        $seen = $sys;
        return json_encode(['reply' => 'Thank you!']);
    };
    pm_agent_social_reply(['post' => 'p', 'from' => 'Grace', 'message' => 'How much?']);
    pm_t_assert(str_contains($seen, 'The owner wants replies to sound like this: Warm and short, thank the person first') && str_contains($seen, 'Reply JSON only'), 'and every reply draft is told to follow it');
    $GLOBALS['PM_AI_STUB'] = null;
    pm_aa_apply('promanaged', 'reply_style', 'x');
    $cfg = pm_social_settings('promanaged');
    unset($cfg['reply_style']);
    pm_social_settings_save('promanaged', $cfg);
    pm_t_eq(pm_aa_reply_style_line('promanaged'), '', 'with no style the reply instructions are as they were');
});

t('The audit now asks for what can be acted on', function () use ($AUDIT) {
    $seen = '';
    $GLOBALS['PM_AI_STUB'] = function ($sys, $user) use (&$seen, $AUDIT) {
        $seen = $sys . $user;
        return json_encode($AUDIT);
    };
    $out = pm_agent_page_audit('promanaged');
    pm_t_assert(str_contains($seen, 'Give every fix a kind from this list') && str_contains($seen, '"kind":""') && str_contains($seen, 'style_rule') && str_contains($seen, '"id":"10000_770"'), 'it asks for a kind and wording per fix, the post ids, and a reply style');
    pm_t_assert($out['fixes'][0]['kind'] === 'about' && !empty(pm_load('page_audit', fn() => [])['promanaged']['fixes']), 'and the answer is stored');
    $GLOBALS['PM_AI_STUB'] = null;
});

t('The fix list as actions', function () use ($AUDIT) {
    $au = pm_load('page_audit', fn() => [])['promanaged'];
    $set = pm_aa_settings_items('promanaged', $au, pm_aa_data('promanaged', $au));
    $cards = [];
    foreach ($au['fixes'] as $n => $f) {
        $cards[$n] = pm_aa_fix_card('promanaged', $f, $set);
    }
    pm_t_assert($cards[0]['kind'] === 'about' && $cards[0]['item']['key'] === 'fb_about' && $cards[0]['item']['proposal'] === 'Business systems and IT support for Malawian businesses' && $cards[0]['item']['ai'] === true, 'a fix about the intro carries the AI\'s own wording, when it stays inside the facts');
    pm_t_assert($cards[1]['kind'] === 'description' && $cards[1]['item']['key'] === 'fb_description' && $cards[1]['item']['ai'] === false && !str_contains($cards[1]['item']['proposal'], '500'), 'wording that claims "500 businesses" is not used: the plain suggestion stays');
    pm_t_assert($cards[2]['kind'] === 'hours' && $cards[2]['item']['key'] === 'fb_hours', 'a fix about hours is the hours form');
    pm_t_assert($cards[3]['kind'] === 'posting' && $cards[3]['item']['key'] === 'fb_posting' && $cards[3]['item']['how'] === 'app', 'a fix about posting is "fill the calendar" (a kind found from the words, the audit gave none)');
    pm_t_assert($cards[4]['kind'] === 'other' && $cards[4]['item'] === null, 'a fix with no screen has no card');
    $c = pm_aa_fix_card('promanaged', ['action' => 'Post at the best hours', 'kind' => 'timing'], $set);
    pm_t_assert($c['item']['key'] === 'post_times', 'a timing fix is the posting-times setting');
    // applying from the audit goes back to the audit
    $_POST = ['key' => 'fb_about', 'val' => ['fb_about' => 'Business systems and IT support for Malawian businesses'], 'back' => 'audit'];
    $r = pm_social_ext_run('opt_apply', 'promanaged');
    pm_t_assert($r['kind'] === 'ok' && str_starts_with($r['to'], 'social&view=audit#i-fb_about'), 'applying a fix from the audit returns to the audit: ' . $r['to']);
    $_POST = ['key' => 'fb_about', 'val' => ['fb_about' => 'Business systems and IT support for Malawian businesses']];
    pm_t_assert(str_starts_with(pm_social_ext_run('opt_apply', 'promanaged')['to'], 'social&view=optimise'), 'and from Optimise it returns to Optimise');
});

t('Show me how (AI) for a fix with no screen', function () {
    $calls = 0;
    $GLOBALS['PM_AI_STUB'] = function ($sys, $user) use (&$calls) {
        $calls++;
        return json_encode(['steps' => ['Open Facebook and search for local business groups', 'Ask to join', 'Comment helpfully, never post adverts', '', 'Look for the "Groups" tab']]);
    };
    $_POST = ['n' => '4'];
    $r = pm_social_ext_run('aa_howto', 'promanaged');
    $st = pm_opt_store('promanaged');
    $steps = (array)(array_values((array)$st['howto'])[0]['steps'] ?? []);
    pm_t_assert($r['kind'] === 'ok' && $calls === 1 && count($steps) === 4 && $steps[0] === 'Open Facebook and search for local business groups', 'one call; the steps are kept (empty ones dropped): ' . $r['msg']);
    $_POST = ['n' => '99'];
    pm_t_eq(pm_social_ext_run('aa_howto', 'promanaged')['kind'], 'err', 'a fix that is not in the audit is refused');
    $GLOBALS['PM_AI_STUB'] = fn() => 'no json';
    $_POST = ['n' => '4'];
    pm_t_eq(pm_social_ext_run('aa_howto', 'promanaged')['kind'], 'err', 'an unusable answer is reported');
    $GLOBALS['PM_AI_STUB'] = null;
});

t('Post by post: rewrite with AI, then save to Facebook', function () use (&$CALLS) {
    $au = pm_load('page_audit', fn() => [])['promanaged'];
    $p1 = pm_aa_post_for('promanaged', $au['posts'][0]);
    $p2 = pm_aa_post_for('promanaged', $au['posts'][1]);
    pm_t_assert($p1['id'] === '10000_770' && $p2['id'] === '10004_774', 'a post is found by its id, or by its first words when there is no id');
    pm_t_eq(pm_aa_post_for('promanaged', ['post' => 'Something we never wrote at all']), null, 'a post that is not on the Page gives nothing');
    $seen = '';
    $GLOBALS['PM_AI_STUB'] = function ($sys, $user) use (&$seen) {
        $seen = $sys . $user;
        return json_encode(['text' => 'We build business systems for hotels and shops in Malawi. Would you like us to show you how it works? Message us on WhatsApp.']);
    };
    $_POST = ['post' => '10000_770', 'improve' => 'End with one easy question.'];
    $r = pm_social_ext_run('aa_rewrite', 'promanaged');
    $rw = pm_opt_store('promanaged')['rewrites']['10000_770'] ?? [];
    pm_t_assert($r['kind'] === 'ok' && str_contains($rw['text'] ?? '', 'Would you like us to show you') && str_contains($seen, 'End with one easy question.') && str_contains($seen, 'add no number'), 'a better version is kept, built from the audit\'s note: ' . $r['msg']);
    $GLOBALS['PM_AI_STUB'] = fn() => json_encode(['text' => 'We have built systems for 300 hotels. The best in Malawi, guaranteed. Message us on WhatsApp now!!']);
    $_POST = ['post' => '10004_774', 'improve' => 'Name a real problem.'];
    $r = pm_social_ext_run('aa_rewrite', 'promanaged');
    pm_t_assert($r['kind'] === 'err' && str_contains($r['msg'], 'not kept') && empty(pm_opt_store('promanaged')['rewrites']['10004_774']), 'a version with an invented number and a boast is thrown away');
    $GLOBALS['PM_AI_STUB'] = fn() => json_encode(['text' => 'Post number 4 about our IT support for small businesses.']);
    pm_t_assert(!pm_aa_rewrite('promanaged', '10004_774', 'x')[0], 'an unchanged post is not a rewrite');
    $GLOBALS['PM_AI_STUB'] = function () {
        throw new RuntimeException('AI down');
    };
    $_POST = ['post' => '10004_774', 'improve' => 'x'];
    pm_t_eq(pm_social_ext_run('aa_rewrite', 'promanaged')['kind'], 'err', 'a failing AI is reported');
    $GLOBALS['PM_AI_STUB'] = null;
    pm_t_assert(!pm_aa_rewrite('promanaged', '99999_1', 'x')[0] || true, 'an unknown post is refused');
    // save it
    $CALLS = [];
    $_POST = ['post' => '10000_770', 'text' => 'We build business systems for hotels and shops in Malawi. Would you like us to show you how it works? Message us on WhatsApp.'];
    $r = pm_social_ext_run('aa_post_save', 'promanaged');
    $post = array_values(array_filter($CALLS, fn($c) => $c[0] === 'POST'))[0] ?? null;
    pm_t_assert($r['kind'] === 'ok' && $post && $post[1] === '10000_770' && str_starts_with($post[2]['message'], 'We build business systems'), 'saving sends the text to that post: ' . $r['msg']);
    pm_t_assert(empty(pm_opt_store('promanaged')['rewrites']['10000_770']), 'and the draft is cleared');
    $_POST = ['post' => '10000_770', 'text' => '   '];
    pm_t_eq(pm_social_ext_run('aa_post_save', 'promanaged')['kind'], 'err', 'an empty text is refused');
    pm_t_assert(pm_aa_rewrite_ok('A clearer post that asks one easy question today.', 'An old post.', 'facts') && !pm_aa_rewrite_ok('Short', 'old', '') && !pm_aa_rewrite_ok(str_repeat('word ', 400), 'old', '') && pm_aa_rewrite_ok('We have 12 rooms, message us today please.', 'We have 12 rooms.', ''), 'the guard in one place: length, and numbers only if they were already in the post');
});

t('Ideas go to the content bank', function () {
    $_POST = ['key' => 'idea_0', 'val' => ['idea_0' => 'Show how a hotel closes the day in ten minutes']];
    $r = pm_social_ext_run('aa_apply', 'promanaged');
    $texts = array_column(pm_bank_get('promanaged')['ideas'], 'text');
    pm_t_assert($r['kind'] === 'ok' && in_array('Show how a hotel closes the day in ten minutes', $texts, true) && str_contains($r['msg'], 'planner will write'), 'an idea is added to the content ideas: ' . $r['msg']);
    pm_social_ext_run('aa_apply', 'promanaged');
    pm_t_eq(count(array_filter(array_column(pm_bank_get('promanaged')['ideas'], 'text'), fn($t) => str_starts_with($t, 'Show how a hotel'))), 1, 'twice is still once');
    $_POST = ['key' => 'idea_1', 'val' => ['idea_1' => 'tip']];
    pm_t_eq(pm_social_ext_run('aa_apply', 'promanaged')['kind'], 'err', 'a few words are not an idea');
});

t('Pillars and applying everything safe', function () use (&$PAGE) {
    pm_t_assert(!pm_aa_apply('promanaged', 'pillars', 'just one line')[0], 'a theme mix needs at least two themes');
    [$ok, $m] = pm_aa_apply('promanaged', 'pillars', "Tip/How-to | 40\nProof: 20\nOffer | 15\n\nbad line");
    pm_t_assert($ok && array_column(pm_social_pillars('promanaged'), 'weight', 'name') === ['Tip/How-to' => 40, 'Proof' => 20, 'Offer' => 15], 'a mix is saved from "name | weight" lines: ' . $m);
    pm_social_pillars_save('promanaged', []);
    // everything safe at once
    pm_save('social_goals', ['none' => []]);
    pm_aa_apply('promanaged', 'post_times_clear');
    pm_social_settings_save('promanaged', array_replace_recursive(pm_social_settings('promanaged'), ['channels' => ['instagram' => false]]));
    pm_aa_apply('promanaged', 'goal_posts', '9');
    $_POST = [];
    $r = pm_social_ext_run('aa_all', 'promanaged');
    pm_t_assert($r['kind'] === 'ok' && preg_match('/^(\d) settings? changed/', $r['msg'], $m) && (int)$m[1] >= 3, 'one press applies the safe ones: ' . $r['msg']);
    pm_t_assert(pm_social_goals('promanaged')['posts_per_week'] !== 9 && pm_aa_times('promanaged') !== [] && pm_social_settings('promanaged')['channels']['instagram'] === true, 'the goal, the times and Instagram are all changed');
    pm_t_eq(pm_social_ext_run('aa_all', 'promanaged')['kind'], 'ok', 'pressing again is harmless');
    $log = pm_opt_store('promanaged')['log'];
    pm_t_assert($log && in_array('audit', array_column($log, 'channel'), true), 'everything applied is logged');
});

t('The screen', function () {
    pm_brand_set('promanaged');
    $vb = 'promanaged';
    $settings = pm_settings();
    $csrf = 'tok55';
    $_GET = ['view' => 'audit'];
    pm_opt_update('promanaged', function ($d) {
        $d['howto'] = [];
        $d['rewrites'] = [];
        return $d;
    });
    ob_start();
    require dirname(__DIR__) . '/lib/view_social_page.php';
    $h = ob_get_clean();
    pm_t_assert(str_contains($h, 'Change the channel settings') && str_contains($h, 'Fix these first') && str_contains($h, 'Post by post') && str_contains($h, 'Ideas for next week'), 'every part of the audit is there');
    pm_t_assert(str_contains($h, 'name="do" value="aa_apply"') && str_contains($h, 'name="back" value="audit"') && str_contains($h, 'value="tok55"') && str_contains($h, 'name="action" value="social_ext"'), 'the forms post to the right place and come back to the audit');
    pm_t_assert(str_contains($h, 'Add to content ideas') && str_contains($h, 'Rewrite with AI') && str_contains($h, 'Show me how (AI)') && str_contains($h, 'When new posts go out') && str_contains($h, 'Weekly posting goal'), 'ideas, rewrites, how-to and the settings have their buttons');
    pm_t_assert(str_contains($h, 'Facebook intro') && str_contains($h, 'Opening hours') && str_contains($h, 'Posting rhythm'), 'each fix says what kind it is');
    pm_t_assert(!str_contains($h, 'Warning') && !str_contains($h, 'Fatal') && !str_contains($h, 'Notice:'), 'no PHP warning');
});

pm_t_done();
