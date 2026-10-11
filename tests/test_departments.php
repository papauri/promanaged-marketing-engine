<?php
/**
 * Tests for the departments doing one job each: every setting is edited in exactly one place. Each Operations page saves only its own part of the settings; the content bank (ideas, hashtags,
 * auto-publish) has its handlers; the moved pages are where the registry says; and a guard fails when the same editor appears in two screens again.
 * Runs on a TEMP COPY of data/ with the network stubbed. Run: php tests/test_departments.php
 */
require __DIR__ . '/boot.php';
$T = pm_test_boot();
require_once dirname(__DIR__) . '/lib/mail.php'; // what index.php loads for the screens
require_once dirname(__DIR__) . '/lib/wa_biz.php';
require_once dirname(__DIR__) . '/lib/settings_save.php';
file_put_contents($T['env'], "APP_URL=https://app.test\nFB_PAGE_ID=PAGE\nFB_PAGE_TOKEN=T\nIG_USER_ID=IGU\n");
pm_brand_set('promanaged');
$GLOBALS['PM_AI_STUB'] = null;
$GLOBALS['PM_GRAPH_STUB'] = function ($method, $path, $params) {
    if ($path === 'PAGE') {
        return [true, ['name' => 'P', 'about' => '', 'description' => '', 'website' => '', 'phone' => '', 'emails' => [], 'picture' => ['data' => ['url' => 'u', 'is_silhouette' => false]], 'cover' => ['source' => 'c'], 'hours' => [], 'location' => null, 'fan_count' => 12, 'followers_count' => 12]];
    }
    return [true, ['data' => []]];
};
foreach (['page_opt', 'cover_studio', 'social_bank', 'page_audit', 'social_channels'] as $f) {
    pm_save($f, []);
}

function t(string $name, callable $fn): void
{
    echo "\n$name\n";
    try {
        $fn();
    } catch (Throwable $e) {
        pm_t_assert(false, "$name threw " . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

/** Renders a view file as the app does, for a business. */
function page(string $file, string $vb = 'promanaged', array $get = [], string $section = ''): string
{
    pm_brand_set($vb);
    $GLOBALS['vb'] = $vb;
    $_GET = $get;
    $_POST = [];
    $settings = pm_settings();
    $csrf = 'tokD';
    $GLOBALS['PM_DEPT'] = pm_ui_dept_of(str_contains($file, 'social') ? 'social' : (str_contains($file, 'sales') ? 'targeting' : 'settings'), (string)($get['view'] ?? ''), $section);
    ob_start();
    include dirname(__DIR__) . '/lib/' . $file;
    return (string)ob_get_clean();
}

/* ---------------------------------------------------------------- settings: one part per page */

t('Each Operations page saves only its own part', function () {
    pm_update('settings', function (array $s) {
        $s['company_name'] = 'Alpha Co';
        $s['smtp'] = ['host' => 'smtp.alpha.test', 'port' => 465, 'encryption' => 'ssl', 'username' => 'a', 'password' => 'pw', 'from_email' => 'a@alpha.test', 'from_name' => 'Alpha', 'bcc_self' => false];
        $s['team'] = ['Ann'];
        $s['brain']['about'] = 'About Alpha';
        $s['social_auto'] = true;
        $s['travel']['company_name'] = 'Travel Co';
        return $s;
    }, 'pm_default_settings');
    $save = function (array $post): array {
        $_POST = $post;
        return pm_settings_save(pm_settings(), 'promanaged');
    };
    $r = $save(['_sec' => 'email', 's' => ['company_name' => 'SHOULD NOT CHANGE', 'team' => 'Bob', 'smtp' => ['host' => 'mail.new.test', 'port' => '587', 'encryption' => 'tls', 'username' => 'u2', 'from_email' => 'x@new.test', 'from_name' => 'Z', 'bcc_self' => '1']]]);
    $s = pm_settings();
    pm_t_assert($r['kind'] === 'ok' && $r['to'] === 'settings&section=email', 'the Email page saves and comes back to itself: ' . $r['to']);
    pm_t_assert($s['smtp']['host'] === 'mail.new.test' && $s['smtp']['password'] === 'pw' && $s['smtp']['bcc_self'] === true, 'the mail server changed, the saved password was kept');
    pm_t_assert($s['company_name'] === 'Alpha Co' && $s['team'] === ['Ann'] && $s['brain']['about'] === 'About Alpha' && $s['social_auto'] === true, 'nothing else moved: name, team, voice and auto-publish are as they were');
    $save(['_sec' => 'business', 's' => ['company_name' => 'Beta Co', 'tagline' => 'Tag', 'phone' => '1', 'email' => 'b@b.test', 'website' => 'b.test', 'address' => 'X', 'signatory_name' => 'S', 'signatory_title' => 'T', 'ref_prefix' => 'BC', 'next_ref' => '7', 'link_url' => 'https://b.test', 'link_on' => '1',
        'travel' => ['company_name' => 'Travel Two', 'ref_prefix' => 'TT', 'charging' => '1']]]);
    $s = pm_settings();
    pm_t_assert($s['company_name'] === 'Beta Co' && $s['next_ref'] === 7 && $s['link_on'] === true && $s['travel']['company_name'] === 'Travel Two' && $s['travel']['charging'] === true, 'Business details saves both businesses\' details');
    pm_t_assert($s['smtp']['host'] === 'mail.new.test' && $s['team'] === ['Ann'] && $s['brain']['about'] === 'About Alpha' && $s['social_auto'] === true, 'and leaves the mail login, the team, the voice and auto-publish alone');
    $save(['_sec' => 'team', 's' => ['currency' => 'MWK', 'team' => "Cy\nDee | editor", 'currencies' => [['code' => 'USD', 'name' => 'US dollar', 'mpu' => '1700', 'round' => '1']], 'default_quote_currency' => 'USD', 'rates_date' => '2026-10-01', 'company_name' => 'NOPE']]);
    $s = pm_settings();
    pm_t_assert($s['team'] === ['Cy', 'Dee | editor'] && $s['default_quote_currency'] === 'USD' && $s['rates_date'] === '2026-10-01' && $s['company_name'] === 'Beta Co' && $s['smtp']['host'] === 'mail.new.test', 'Team and currencies saves the team and the currencies and nothing else');
    $r = $save(['_sec' => 'voice', 'back' => 'social&view=voice', 's' => ['brain' => ['about' => 'New about', 'facts' => "One\nTwo", 'audience' => 'Everyone', 'voice' => 'Plain', 'never' => 'Never'], 'travel' => ['brain' => ['about' => 'Travel about']], 'company_name' => 'NOPE2']]);
    $s = pm_settings();
    pm_t_assert($r['to'] === 'social&view=voice' && $s['brain']['about'] === 'New about' && $s['brain']['facts'] === "One\nTwo" && $s['travel']['brain']['about'] === 'Travel about' && $s['company_name'] === 'Beta Co', 'Voice saves what the AI knows, comes back to Brand > Voice, and leaves the rest');
    $save(['_sec' => 'voice', 's' => ['brain' => ['about' => 'Only about']]]);
    pm_t_assert(pm_settings()['brain']['facts'] === "One\nTwo", 'a voice field that was not sent is not blanked');
    $save(['_sec' => 'social', 's' => ['social_auto' => '0', 'travel' => []]]);
    pm_t_assert(pm_settings()['social_auto'] === false, 'the social part switches auto-publish');
    $GLOBALS['PM_WHO'] = 'Ed | editor';
    $save(['_sec' => 'social', 's' => ['social_auto' => '1']]);
    pm_t_assert(pm_settings()['social_auto'] === false, 'an editor cannot switch it on');
    $GLOBALS['PM_WHO'] = '';
    $save(['_sec' => 'messages', 's' => ['travel' => ['host_email' => ['intro' => 'We list stays.', 'steps' => "Open {link}.\nWait.", 'benefits' => "One\nTwo", 'link' => 'https://ulendomalawi.com']]], 'back' => 'targeting']);
    $s = pm_settings();
    pm_t_assert($s['travel']['host_email']['intro'] === 'We list stays.' && $s['travel']['host_email']['steps'] === ['Open {link}.', 'Wait.'] && $s['company_name'] === 'Beta Co', 'the first-email wording saves by itself');
    // an older form with no part named still saves everything, as before
    $save(['s' => ['company_name' => 'Gamma', 'currency' => 'MWK', 'team' => 'Solo']]);
    $s = pm_settings();
    pm_t_assert($s['company_name'] === 'Gamma' && $s['team'] === ['Solo'], 'a form that names no part saves everything, as it always did');
    $_POST = [];
});

t('A business added in the app saves one part at a time', function () {
    $ANS = ['name' => 'Sunrise Solar', 'sells' => 'We design and install solar systems for homes and small businesses.', 'customers' => 'Home owners', 'sell_to' => 'business', 'cities' => 'Lilongwe', 'targets' => 'schools, clinics',
        'facts' => "We install solar systems\nWe offer a free site visit", 'voice' => 'friendly', 'magnet' => 'a free site visit', 'email' => 'info@sunrise.example', 'phone' => '0999111222', 'website' => 'https://www.sunrise.example'];
    $GLOBALS['PM_AI_STUB'] = null;
    [$id] = pm_brand_create($ANS, pm_brand_draft($ANS)['draft']);
    pm_t_eq($id, 'sunrisesolar', 'a business exists to save');
    $allCfg = pm_load('agents_config', 'pm_agents_default_config');
    $allCfg[$id] = array_replace((array)($allCfg[$id] ?? []), ['sectors' => ['schools', 'clinics'], 'cities' => ['Lilongwe'], 'offerings' => ['Solar: systems'], 'existing_clients' => ['Old Ltd']]);
    pm_save('agents_config', $allCfg);
    $before = (array)pm_settings()['brands'][$id];
    $_POST = ['_sec' => 'voice', 'b' => ['company_name' => 'NOPE', 'brain' => ['about' => 'Sun about', 'facts' => 'F1', 'audience' => 'A', 'voice' => 'V', 'never' => 'N']]];
    $r = pm_brand_save_custom($id);
    $now = (array)pm_settings()['brands'][$id];
    pm_t_assert($r['kind'] === 'ok' && $now['brain']['about'] === 'Sun about' && $now['company_name'] === $before['company_name'] && $now['smtp'] === $before['smtp'], 'the voice saves alone');
    $_POST = ['_sec' => 'email', 'b' => ['smtp' => ['host' => 'mail.sun.test', 'port' => '465', 'encryption' => 'ssl', 'username' => 'u', 'from_email' => 'i@sun.test', 'from_name' => 'Sun'], 'imap' => ['host' => 'imap.sun.test', 'port' => '993', 'secure' => 'ssl']]];
    pm_brand_save_custom($id);
    $now = (array)pm_settings()['brands'][$id];
    pm_t_assert($now['smtp']['host'] === 'mail.sun.test' && $now['imap']['host'] === 'imap.sun.test' && $now['brain']['about'] === 'Sun about', 'the email part saves the mailbox and keeps the voice');
    $_POST = ['_sec' => 'details', 'b' => ['company_name' => 'Sunrise Solar Ltd', 'accent_color' => '#0f766e', 'ref_prefix' => 'ss', 'link_url' => 'https://sun.test', 'link_on' => '1']];
    pm_brand_save_custom($id);
    $now = (array)pm_settings()['brands'][$id];
    pm_t_assert($now['company_name'] === 'Sunrise Solar Ltd' && $now['ref_prefix'] === 'SS' && $now['link_on'] === true && $now['smtp']['host'] === 'mail.sun.test', 'the details part saves the name and the link');
    $_POST = ['_sec' => 'targeting', 'back' => 'targeting', 'b' => ['customers' => 'Schools', 'magnet' => 'a free visit', 'cta_keyword' => 'visit', 'sell_to' => 'both', 'daily_run' => '1', 'tg_segments' => "schools | They lose lessons | diesel generator", 'tg_skip' => 'chains', 'tg_angles' => 'Reliable power']];
    $r = pm_brand_save_custom($id);
    $prof = pm_brand_profile($id);
    $ac = pm_load('agents_config', fn() => [])[$id];
    pm_t_assert($r['to'] === 'targeting&brand=' . $id && $prof['cta_keyword'] === 'VISIT' && $prof['sell_to'] === 'both' && (pm_brands_custom()[$id]['daily_run'] ?? false) === true, 'the targeting part saves who buys and the first step, and returns to Sales > Targeting');
    pm_t_assert($ac['sectors'] === ['schools', 'clinics'] && $ac['existing_clients'] === ['Old Ltd'] && $ac['offerings'] === ['Solar: systems'], 'what is sold and who to look for are not touched: the agent settings own them');
    $_POST = [];
});

/* ---------------------------------------------------------------- the content bank */

t('The idea bank, hashtags and auto-publish', function () {
    $_POST = ['text' => 'ab'];
    pm_t_eq(pm_social_ext_run('idea_add', 'promanaged')['kind'], 'err', 'an idea that is too short is refused');
    $_POST = ['text' => '  Show how we back up a hotel\'s booking system <b>every night</b>  ', 'pillar' => 'Not a pillar'];
    $r = pm_social_ext_run('idea_add', 'promanaged');
    $mine = pm_content_ideas('promanaged');
    pm_t_assert($r['kind'] === 'ok' && $r['to'] === 'social&view=content#ideas' && count($mine) === 1 && $mine[0]['text'] === 'Show how we back up a hotel\'s booking system every night' && $mine[0]['pillar'] === '' && $mine[0]['active'] === true, 'a good idea is stored clean: tags stripped, an unknown pillar becomes "any"');
    $_POST = ['text' => 'SHOW HOW WE BACK UP A HOTEL\'S BOOKING SYSTEM EVERY NIGHT'];
    pm_t_eq(pm_social_ext_run('idea_add', 'promanaged')['kind'], 'err', 'the same idea twice is refused, whatever the capitals');
    $name = pm_social_pillars('promanaged')[0]['name'];
    $_POST = ['text' => 'A second idea about our support hours', 'pillar' => $name];
    pm_social_ext_run('idea_add', 'promanaged');
    pm_t_eq(array_column(pm_content_ideas('promanaged'), 'pillar'), ['', $name], 'a real pillar is kept');
    $id = $mine[0]['id'];
    $_POST = ['id' => $id];
    pm_t_assert(pm_social_ext_run('idea_toggle', 'promanaged')['kind'] === 'ok' && pm_content_ideas('promanaged')[0]['active'] === false, 'an idea can be paused');
    pm_social_ext_run('idea_toggle', 'promanaged');
    pm_t_assert(pm_content_ideas('promanaged')[0]['active'] === true, 'and used again');
    pm_t_assert(pm_social_ext_run('idea_delete', 'promanaged')['kind'] === 'ok' && count(pm_content_ideas('promanaged')) === 1, 'and deleted');
    pm_t_eq(pm_social_ext_run('idea_delete', 'promanaged')['kind'], 'err', 'deleting it twice says it is gone');
    $_POST = ['id' => 'nope'];
    pm_t_eq(pm_social_ext_run('idea_toggle', 'promanaged')['kind'], 'err', 'an unknown idea is refused');
    for ($i = 0; $i < PM_CONTENT_MAX_IDEAS + 2; $i++) {
        $_POST = ['text' => 'Bulk idea number ' . $i . ' about our work'];
        $last = pm_social_ext_run('idea_add', 'promanaged');
    }
    pm_t_assert($last['kind'] === 'err' && count(pm_content_ideas('promanaged')) === PM_CONTENT_MAX_IDEAS, 'the bank holds ' . PM_CONTENT_MAX_IDEAS . ' of your own ideas and says so');
    $_POST = [];
    pm_social_ext_run('idea_builtin', 'promanaged');
    pm_t_eq(pm_bank_get('promanaged')['builtin_ideas'], false, 'the built-in starters can be switched off');
    $_POST = ['on' => '1'];
    pm_social_ext_run('idea_builtin', 'promanaged');
    pm_t_eq(pm_bank_get('promanaged')['builtin_ideas'], true, 'and on');
    pm_t_eq(pm_content_tags("Malawi #Lilongwe, malawi  small-business\n#Lilongwe"), ['#Malawi', '#Lilongwe', '#malawi', '#smallbusiness'], 'hashtags are cleaned: one # in front, no punctuation, no repeats');
    pm_t_eq(count(pm_content_tags(implode(' ', range(1, 40)))), 15, 'and limited to fifteen');
    $_POST = ['local' => 'Malawi Lilongwe', 'niche' => ''];
    pm_social_ext_run('tags_save', 'promanaged');
    $tags = pm_bank_get('promanaged')['tags'];
    pm_t_assert($tags['local'] === ['#Malawi', '#Lilongwe'] && $tags['niche'] !== [], 'saved hashtags are used, and an empty box goes back to the built-in set');
    // auto-publish
    $_POST = [];
    $GLOBALS['PM_WHO'] = '';
    pm_social_ext_run('autopublish', 'promanaged');
    pm_t_eq(!empty(pm_settings()['social_auto']), false, 'auto-publish off');
    $_POST = ['on' => '1'];
    $r = pm_social_ext_run('autopublish', 'travel');
    pm_t_assert(!empty(pm_settings()['travel']['social_auto']) && empty(pm_settings()['social_auto']) && $r['to'] === 'social#rules', 'it is per business: Travel Malawi on, ProManaged IT unchanged');
    $GLOBALS['PM_WHO'] = 'Ed | editor';
    $_POST = [];
    pm_t_assert(pm_social_ext_run('autopublish', 'travel')['kind'] === 'err' && !empty(pm_settings()['travel']['social_auto']), 'an editor cannot switch it');
    $GLOBALS['PM_WHO'] = '';
    $_POST = [];
});

t('An audit setting applied from Plan or the Inbox comes back there', function () {
    $_POST = ['back' => 'social'];
    pm_t_eq(pm_aa_to('i-x'), 'social#i-x', 'from the Plan');
    $_POST = ['back' => 'social&view=inbox'];
    pm_t_eq(pm_aa_to('i-x'), 'social&view=inbox#i-x', 'from the Inbox');
    $_POST = ['back' => 'audit'];
    pm_t_eq(pm_aa_to('i-x'), 'social&view=audit#i-x', 'from the Audit');
    $_POST = ['back' => 'evil&x=1'];
    pm_t_eq(pm_aa_to(), 'social&view=audit', 'and nowhere else');
    $_POST = [];
    $keys = array_column(pm_aa_items_for('promanaged', ['post_times']), 'key');
    pm_t_eq($keys, ['post_times'], 'the Plan can ask for just its own setting suggestions, with no network');
});

/* ---------------------------------------------------------------- the pages */

t('Every page renders and says whose job it is', function () {
    $h = page('view_social_content.php', 'promanaged', ['view' => 'content']);
    pm_t_assert(!preg_match('/(Warning|Fatal error|Notice):/', $h) && str_contains($h, 'id="pillars"') && str_contains($h, 'id="mix"') && str_contains($h, 'id="ideas"') && str_contains($h, 'id="proof"') && str_contains($h, 'id="tags"') && str_contains($h, 'id="recycle"') && str_contains($h, 'name="do" value="idea_add"'), 'Content has the pillars, the mix, the ideas, the proof, the hashtags and what to bring back');
    pm_t_assert(str_contains($h, 'Social department') && str_contains($h, '>Content · '), 'and names its department');
    $h = page('view_social.php', 'promanaged', ['view' => '']);
    pm_t_assert(!preg_match('/(Warning|Fatal error|Notice):/', $h) && str_contains($h, 'id="rules"') && str_contains($h, 'name="do" value="autopublish"') && str_contains($h, 'name="channels[facebook]"') && str_contains($h, 'Publishing rules'), 'the Plan has the publishing rules');
    pm_t_assert(!str_contains($h, 'Ready to post well?') && !str_contains($h, '<h2>Comments to answer</h2>') && !str_contains($h, '<h2>On the Page now</h2>'), 'and no longer repeats the readiness list, the comments or the Page\'s posts');
    $h = page('view_social_page.php', 'promanaged', ['view' => 'inbox']);
    pm_t_assert(!preg_match('/(Warning|Fatal error|Notice):/', $h) && str_contains($h, 'Community rules') && str_contains($h, 'name="do" value="autopilot"') && str_contains($h, 'name="do" value="judge"'), 'the Inbox owns autopilot and the check of the Page');
    $h = page('view_social_growth.php', 'promanaged', ['view' => 'growth']);
    pm_t_assert(!preg_match('/(Warning|Fatal error|Notice):/', $h) && str_contains($h, 'id="playbook"') && !str_contains($h, '<h2>Autopilot</h2>') && !str_contains($h, 'Today on the Page'), 'the Playbook is the playbook and the people only');
    $h = page('view_social_channels.php', 'promanaged', ['view' => 'channels']);
    pm_t_assert(!str_contains($h, 'Profile health') && !str_contains($h, 'Bio pack') && str_contains($h, 'Google Business pack'), 'Channels keeps the hand-posting and gives the profile health to Brand');
    $h = page('view_social_optimise.php', 'promanaged', ['view' => 'optimise']);
    pm_t_assert(!preg_match('/(Warning|Fatal error|Notice):/', $h) && str_contains($h, 'id="words"') && str_contains($h, 'name="do" value="copy_write"') && str_contains($h, 'name="do" value="opt_apply"'), 'Profiles has the profile words and the fixes');
    $h = page('view_social_studio.php', 'promanaged', ['view' => 'studio', 'd' => 'new']);
    pm_t_assert(!str_contains($h, 'name="do" value="copy_write"') && str_contains($h, 'Brand and creative department'), 'the Studio is for the look: the words moved to Profiles');
    $h = page('view_social_voice.php', 'promanaged', ['view' => 'voice']);
    pm_t_assert(str_contains($h, 'name="s[brain][about]"') && str_contains($h, 'name="_sec" value="voice"') && str_contains($h, 'name="action" value="settings"'), 'Voice edits what the AI knows (ProManaged IT)');
    $h = page('view_social_voice.php', 'travel', ['view' => 'voice']);
    pm_t_assert(str_contains($h, 'name="s[travel][brain][about]"') && !str_contains($h, 'name="s[brain][about]"'), 'and Travel Malawi\'s');
    $h = page('view_social_kit.php', 'promanaged', ['view' => 'kit']);
    pm_t_assert(!preg_match('/(Warning|Fatal error|Notice):/', $h) && str_contains($h, 'name="_sec" value="kit"') && str_contains($h, 'name="cover_head"') && !str_contains($h, 'name="channels[facebook]"') && !str_contains($h, 'name="url[facebook]"'), 'the Brand kit has the cover wording and pictures, not the channels or the links');
    $h = page('view_social_results.php', 'promanaged', ['view' => 'results']);
    pm_t_assert(!preg_match('/(Warning|Fatal error|Notice):/', $h) && !str_contains($h, 'Numbers you type in') && !str_contains($h, 'name="do" value="apply_weights"') && !str_contains($h, 'name="do" value="recycle"') && str_contains($h, 'Goals and limits') && str_contains($h, 'Insights department'), 'Results reads the numbers; typing them in, the mix and recycling are elsewhere');
    $h = page('view_social_numbers.php', 'promanaged', ['view' => 'numbers']);
    pm_t_assert(!preg_match('/(Warning|Fatal error|Notice):/', $h) && str_contains($h, 'Numbers you type in') && str_contains($h, 'name="do" value="followers_manual"') && str_contains($h, 'name="do" value="ads_manual"'), 'Enter numbers has the forms');
    pm_t_assert(!str_contains(page('view_social_results.php', 'promanaged', ['view' => 'results']), '<h2>Log an enquiry</h2>'), 'and Results no longer has the sales form for logging an enquiry');
    $h = page('view_social_page.php', 'promanaged', ['view' => 'audit']);
    pm_t_assert(!preg_match('/(Warning|Fatal error|Notice):/', $h) && str_contains($h, 'Where each setting lives') && !str_contains($h, 'name="do" value="aa_all"'), 'the Audit judges and points to the owner of each setting');
});

t('Operations pages', function () {
    $h = page('view_settings.php', 'promanaged', ['tab' => 'settings'], 'business');
    $GLOBALS['section'] = 'business';
    $csrf = 'tokD';
    foreach (['business' => ['name="_sec" value="business"', 'name="s[company_name]"', 'Signing and numbering'], 'email' => ['name="_sec" value="email"', 'name="s[smtp][host]"', 'Email health'],
        'connections' => ['Facebook connection', 'name="_sec" value="links"', 'Pictures, Instagram and the scheduler', 'Enquiry form for your website'], 'health' => ['id="health"', 'id="backups"'], 'team' => ['name="_sec" value="team"', 'name="s[team]"', 'Currencies']] as $sec => $needles) {
        pm_brand_set('promanaged');
        $_GET = ['tab' => 'settings', 'section' => $sec];
        $settings = pm_settings();
        $vb = 'promanaged';
        $section = $sec;
        $GLOBALS['PM_DEPT'] = 'ops';
        ob_start();
        include dirname(__DIR__) . '/lib/view_settings.php';
        $h = (string)ob_get_clean();
        $missing = array_filter($needles, fn($n) => !str_contains($h, $n));
        pm_t_assert(!preg_match('/(Warning|Fatal error|Notice):/', $h) && !$missing && str_contains($h, 'Operations'), "the $sec page: " . ($missing ? 'missing ' . implode(', ', $missing) : 'complete'));
        pm_t_assert(!str_contains($h, 'name="s[brain]') && !str_contains($h, 'name="s[social_auto]"') && !str_contains($h, 'Marketing brain'), "$sec has no voice or auto-publish editor");
    }
    $_GET = [];
});

t('Sales > Targeting and messages', function () {
    $h = page('view_sales_targeting.php', 'promanaged', [], '');
    pm_t_assert(!preg_match('/(Warning|Fatal error|Notice):/', $h) && str_contains($h, 'name="do" value="config"') && str_contains($h, 'name="cfg[cities]"') && str_contains($h, 'name="cfg[enabled]') && str_contains($h, 'name="bt" value="targeting"') && str_contains($h, 'Sales department'), 'the agent settings are here, in groups');
    pm_t_assert(str_contains($h, 'class="roster"') && str_contains($h, 'The team\'s diary') && !str_contains($h, 'First email to a stay'), 'with the roster and the diary, and no Travel Malawi wording for ProManaged IT');
    $h = page('view_sales_targeting.php', 'travel', [], '');
    pm_t_assert(str_contains($h, 'First email to a stay') && str_contains($h, 'name="s[travel][host_email][steps]"') && str_contains($h, 'name="_sec" value="messages"') && !str_contains($h, 'name="cfg[offerings]"'), 'Travel Malawi gets the first-email wording, and no "what we sell" box');
    $h = page('view_sales_targeting.php', 'sunrisesolar', [], '');
    pm_t_assert(str_contains($h, 'name="b[tg_segments]"') && str_contains($h, 'name="cfg[existing_clients]"') && str_contains($h, 'One-page offer') && str_contains($h, 'Study the business again') && substr_count($h, 'name="cfg[sectors]"') === 1, 'a business added in the app gets who buys, who to target and its one-page offer, with the sectors asked for once');
    $leads = page('view_agents.php', 'promanaged', ['tab' => 'agents']);
    pm_t_assert(!str_contains($leads, 'name="cfg[cities]"') && !str_contains($leads, '<h3>Agent settings</h3>') && !str_contains($leads, '<h3>The agents</h3>') && str_contains($leads, 'Targeting and messages'), 'the Leads page no longer carries the settings, the roster or the diary');
});

/* ---------------------------------------------------------------- one owner per setting */

t('No setting has two editors', function () {
    $own = [ // a piece of each editor's source (forms are built by small closures, so the markup is not always literal) => the one view that has it
        'name="pillar_name[]"' => 'view_social_content.php', 'name="proof[' => 'view_social_content.php', 'name="cfg[cities]"' => 'view_sales_targeting.php', 'name="cfg[sectors]"' => 'view_sales_targeting.php',
        'name="cfg[send_cap]"' => 'view_sales_targeting.php', 'name="s[team]"' => 'view_ops_team.php', 'name="s[currency]"' => 'view_ops_team.php', '[brain][$k]' => 'view_social_voice.php',
        'name="channels[facebook]"' => 'view_social.php', "'autopublish'" => null, 'value="autopublish"' => 'view_social.php', 'copy_write' => 'view_social_optimise.php', 'name="url[' => 'view_ops_connections.php',
        'connect_pick' => 'view_ops_connections.php', 'name="cover_head"' => 'view_social_kit.php', "('autopilot')" => 'view_social_page.php', "('judge')" => 'view_social_page.php',
        "\$n('smtp')" => 'view_ops_email.php', '[smtp][host]' => 'view_ops_email.php', "'goals_save'" => 'view_social_results.php', "'followers_manual'" => 'view_social_numbers.php',
        "'results_save'" => 'view_social_numbers.php', "'ads_manual'" => 'view_social_numbers.php', "'apply_weights'" => 'view_social_content.php', "'recycle'" => 'view_social_content.php',
        "'idea_add'" => 'view_social_content.php', '[host_email]' => 'view_sales_targeting.php', "host_email') ?>" => null, 'name="b[tg_segments]"' => 'view_sales_targeting.php',
        'brand_archive' => 'view_ops_business.php', "\$n('company_name')" => 'view_ops_business.php',
        "'cd_run'" => 'view_social_kit.php', "'pd_run'" => 'view_social_kit.php', "'bt_run'" => 'view_social_kit.php', "'ir_run'" => 'view_social_content.php', 'name="hero"' => 'view_social_kit.php',
    ];
    $own = array_filter($own, fn($v) => $v !== null);
    $files = glob(dirname(__DIR__) . '/lib/view_*.php');
    foreach ($own as $needle => $owner) {
        $in = [];
        foreach ($files as $f) {
            if (str_contains(str_replace("\r\n", "\n", (string)file_get_contents($f)), $needle)) {
                $in[] = basename($f);
            }
        }
        pm_t_assert($in === [$owner], "$needle is edited in " . $owner . ' only' . ($in === [$owner] ? '' : ' (found in ' . (implode(', ', $in) ?: 'no screen') . ')'));
    }
    pm_t_assert(!is_file(dirname(__DIR__) . '/lib/view_social_accounts.php') && !is_file(dirname(__DIR__) . '/lib/view_brand_settings.php'), 'the two screens that mixed everything are gone');
});

pm_t_done();
