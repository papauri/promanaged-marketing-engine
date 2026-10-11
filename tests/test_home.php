<?php
/**
 * Tests for the firm as departments: the registry (every screen belongs to one department, with a colour and an icon), the top bar and page head that follow it, the tab strip that shows a
 * department's own pages, and the agency overview (numbers, department states, the team roster, the activity feed) built from stored data only. Run: php tests/test_home.php
 */
require __DIR__ . '/boot.php';
$T = pm_test_boot();
file_put_contents($T['env'], "APP_URL=https://app.test\nFB_PAGE_ID=PAGE\nFB_PAGE_TOKEN=T\n");
$GLOBALS['PM_AI_STUB'] = null;
$GLOBALS['PM_GRAPH_STUB'] = function () {
    throw new RuntimeException('the overview must not call Facebook');
};
pm_brand_set('promanaged');
foreach (['leads', 'social_posts', 'agent_log', 'director', 'engage_playbook', 'page_opt', 'cover_studio'] as $f) {
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

t('Every screen belongs to one department', function () {
    $reg = pm_ui_nav();
    pm_t_eq(array_keys($reg), ['hq', 'sales', 'social', 'brand', 'insights', 'ops'], 'six departments, the overview first');
    foreach ($reg as $k => $d) {
        pm_t_assert(preg_match('/^#[0-9a-f]{6}$/', $d['color']) === 1 && isset(PM_UI_ICONS[$k]) && $d['name'] !== '' && $d['href'] !== '', "$k has a colour, an icon, a name and a home link");
    }
    pm_t_assert(count(array_unique(array_column($reg, 'color'))) === 6, 'each department has its own colour');
    foreach (['home' => 'hq', 'agents' => 'sales', 'whatsapp' => 'sales', 'proposal' => 'sales', 'history' => 'sales', 'template' => 'sales', 'settings' => 'ops', 'business' => 'ops'] as $tab => $want) {
        pm_t_eq(pm_ui_dept_of($tab), $want, "tab $tab is in $want");
    }
    foreach (['' => 'social', 'leadposts' => 'social', 'content' => 'social', 'channels' => 'social', 'inbox' => 'social', 'page' => 'social', 'growth' => 'social', 'cleanup' => 'social', 'ads' => 'social',
        'studio' => 'brand', 'optimise' => 'brand', 'accounts' => 'brand', 'results' => 'insights', 'audit' => 'insights'] as $view => $want) {
        pm_t_eq(pm_ui_dept_of('social', $view), $want, "Social view '$view' is in $want");
    }
    pm_t_eq(pm_ui_dept_of('social', 'something-new'), 'social', 'a view nobody has placed stays with Social');
    $all = [];
    foreach (['social', 'brand', 'insights'] as $d) {
        $all = array_merge($all, array_map('strval', array_keys(pm_ui_dept_tabs($d))));
    }
    $old = array_map('strval', array_keys(pm_social_tabs()));
    sort($all);
    sort($old);
    pm_t_eq($all, $old, 'the old full tab list and the departments cover the same Social views');
    $views = [];
    foreach (['social', 'brand', 'insights'] as $d) {
        foreach (array_keys(pm_ui_dept_tabs($d)) as $v) {
            $views[$v] = ($views[$v] ?? 0) + 1;
        }
    }
    pm_t_assert(max($views) === 1, 'no Social view is in two departments');
    pm_t_eq(pm_ui_dept_tabs('sales'), [], 'a department of its own screens has no Social tab strip');
});

t('The top bar and page head follow the department', function () {
    $settings = pm_settings();
    $bar = fn(string $tab, string $view = '') => pm_ui_topbar(['tab' => $tab, 'view' => $view, 'vb' => 'promanaged', 'settings' => $settings, 'csrf' => 'c', 'team' => [], 'who' => '', 'hbAge' => null]);
    $h = $bar('home');
    pm_t_assert(substr_count($h, 'class="navitem"') === 6 && str_contains($h, '>Overview</span>') && str_contains($h, '>Operations</span>') && str_contains($h, 'aria-label="Departments"'), 'one entry per department');
    pm_t_assert(str_contains($h, '<div class="dd navdd on" style="--dept:#2b2f36">'), 'the Overview is marked on the home screen, in its colour');
    $h = $bar('social', 'studio');
    pm_t_assert(str_contains($h, '<div class="dd navdd on" style="--dept:#8a6a2f">') && substr_count($h, 'navdd on') === 1, 'Studio lights up Brand, and only Brand');
    pm_t_assert(preg_match('#class="item on" href="\?tab=social&amp;view=studio"#', $h) === 1, 'and its drop-down marks the page');
    $h = $bar('social', 'results');
    pm_t_assert(str_contains($h, '--dept:#46607a">') && preg_match('#navdd on" style="--dept:\#46607a#', $h) === 1, 'Results lights up Insights');
    $h = $bar('whatsapp');
    pm_t_assert(preg_match('#navdd on" style="--dept:\#2f6b4f#', $h) === 1 && str_contains($h, '>WhatsApp outreach<'), 'WhatsApp is a Sales page');
    pm_t_assert(!str_contains($bar('home'), 'aria-label="Overview pages"') && str_contains($bar('home'), 'aria-label="Sales pages"'), 'a department with one page has no drop-down');
    $GLOBALS['PM_DEPT'] = 'brand';
    $head = pm_ui_head('Studio · X', 'Words');
    pm_t_assert(str_contains($head, 'class="eyebrow"') && str_contains($head, 'Brand and creative department') && str_contains($head, '<svg'), 'a page head names its department with the icon');
    $GLOBALS['PM_DEPT'] = 'hq';
    pm_t_assert(!str_contains(pm_ui_head('Hi'), 'eyebrow'), 'the overview needs no department label');
    $GLOBALS['PM_DEPT'] = '';
    pm_t_assert(!str_contains(pm_ui_head('Hi'), 'eyebrow'), 'nor does a screen with no department');
    pm_t_eq(pm_ui_dept_style('nonsense'), '', 'an unknown department has no colour');
    pm_t_assert(pm_ui_href_on('?tab=social&view=studio', 'social', 'studio') && !pm_ui_href_on('?tab=social&view=studio', 'social', 'results') && pm_ui_href_on('?tab=social', 'social', '') && !pm_ui_href_on('?tab=settings#health', 'settings', ''), 'a link is "on" only for its own screen, and never a #section link');
});

t('The Social tab strip shows one department\'s pages', function () {
    $GLOBALS['PM_DEPT'] = null;
    unset($GLOBALS['PM_DEPT']);
    $h = pm_social_nav('studio');
    pm_t_assert(str_contains($h, 'Brand and creative department') && str_contains($h, '>Studio</a>') && str_contains($h, '>Optimise</a>') && str_contains($h, 'Accounts &amp; branding') && !str_contains($h, '>Lead posts</a>') && !str_contains($h, '>Results</a>'),
        'the Studio shows the Brand pages and not the Social ones');
    pm_t_assert(preg_match('#<a href="\?tab=social&amp;view=studio" class="on" aria-current="page">Studio</a>#', $h) === 1 && str_contains($h, '<h1>Studio · '), 'it marks Studio and titles the page with it');
    unset($GLOBALS['PM_DEPT']);
    $h = pm_social_nav('');
    pm_t_assert(str_contains($h, '>Plan</a>') && str_contains($h, '>Lead posts</a>') && str_contains($h, '>Inbox</a>') && !str_contains($h, '>Studio</a>') && str_contains($h, '<h1>Plan · '), 'the Social plan shows the Social pages');
    unset($GLOBALS['PM_DEPT']);
    $h = pm_social_nav('audit');
    pm_t_assert(str_contains($h, '>Results</a>') && str_contains($h, '>Audit</a>') && str_contains($h, 'Insights department'), 'the Audit sits with Results under Insights');
    unset($GLOBALS['PM_DEPT']);
});

t('The overview numbers come from stored data', function () {
    $now = date('Y-m-d H:i:s');
    $mk = fn(string $id, string $brand, string $status, array $extra = []) => ['id' => $id, 'brand' => $brand, 'name' => "Biz $id", 'status' => $status, 'type' => 'hotels', 'city' => 'Lilongwe', 'updated' => $now] + $extra;
    pm_save('leads', [
        $mk('a', 'promanaged', 'drafted', ['drafts' => ['email_body' => 'Hello']]), $mk('b', 'promanaged', 'drafted', ['drafts' => ['email_body' => 'Hello']]), $mk('c', 'promanaged', 'replied'),
        $mk('d', 'promanaged', 'contacted', ['sent' => [date('Y-m-d') . ' 08:00:00']]), $mk('e', 'travel', 'new'), $mk('f', 'travel', 'replied'),
    ]);
    pm_save('social_posts', [
        ['id' => 'p1', 'brand' => 'promanaged', 'status' => 'draft', 'when' => date('Y-m-d H:i', time() + 86400)],
        ['id' => 'p2', 'brand' => 'promanaged', 'status' => 'approved', 'when' => date('Y-m-d H:i', time() + 3 * 86400)],
        ['id' => 'p3', 'brand' => 'promanaged', 'status' => 'published', 'when' => date('Y-m-d H:i', time() - 5 * 86400)],
        ['id' => 'p4', 'brand' => 'travel', 'status' => 'approved', 'when' => date('Y-m-d H:i', time() + 86400)],
    ]);
    pm_save('director', ['promanaged' => ['date' => date('Y-m-d'), 'at' => time(), 'headline' => 'Clear the drafts first.', 'priorities' => ['Send two drafts', 'Answer Biz c']]]);
    $d = pm_home_data('promanaged');
    pm_t_assert($d['sales']['leads'] === 4 && $d['sales']['replies'] === 1 && $d['sales']['drafts'] === 2 && $d['sales']['sent_today'] === 1, 'sales numbers are this business\'s own: ' . json_encode($d['sales']));
    pm_t_assert($d['social']['planned'] === 1 && $d['social']['waiting'] === 1 && $d['social']['published28'] === 1 && $d['social']['ready'] === true, 'social numbers: one lined up, one waiting, one published, Facebook ready: ' . json_encode($d['social']));
    pm_t_assert($d['brief']['headline'] === 'Clear the drafts first.' && $d['brief']['priorities'] === ['Send two drafts', 'Answer Biz c'], 'the Director\'s brief is shown as stored');
    pm_t_eq(pm_brand(), 'promanaged', 'reading another business restores the one on screen');
    $t = pm_home_data('travel');
    pm_t_assert($t['sales']['leads'] === 2 && $t['sales']['replies'] === 1 && $t['brief']['headline'] === '' && pm_brand() === 'promanaged', 'a business with no brief shows none, and its numbers are its own');
    pm_t_eq(pm_home_glance('travel'), ['leads' => 2, 'replies' => 1, 'posts_waiting' => 0, 'fb' => false], 'the glance for another business');
});

t('Department cards: state, staff, next steps', function () {
    $d = pm_home_data('promanaged');
    $c = pm_home_depts('promanaged', $d);
    pm_t_eq(array_keys($c), ['sales', 'social', 'brand', 'insights', 'ops'], 'five departments below the overview');
    foreach ($c as $k => $x) {
        pm_t_assert(in_array($x['state'], ['ok', 'warn', 'todo'], true) && $x['text'] !== '' && $x['mission'] !== '' && $x['stats'] && $x['next'] && $x['staff'] && $x['on_duty'] <= count($x['staff']), "$k card is complete");
        foreach ($x['next'] as [$label, $href]) {
            pm_t_assert($label !== '' && str_starts_with($href, '?tab='), "$k step '$label' links inside the app");
        }
    }
    $hasKey = pm_agents_ready();
    pm_t_eq($c['sales']['state'], $hasKey ? 'warn' : 'todo', 'sales: the reply waiting needs you' . ($hasKey ? '' : ' (no AI key: it says so first)'));
    pm_t_assert($hasKey ? str_contains($c['sales']['text'], '1 reply waits') : str_contains($c['sales']['text'], 'AI key'), 'and says what: ' . $c['sales']['text']);
    pm_t_assert($c['social']['state'] === 'warn' && str_contains($c['social']['text'], '1 post waits for your approval'), 'social: a post waits for approval');
    pm_t_eq($c['brand']['state'], 'todo', 'brand: no check has been run yet');
    pm_t_assert(count(array_filter($c['sales']['staff'], fn($m) => $m['name'] === 'Proposal writer' && !$m['on'] || $m['name'] === 'Proposal writer')) === 1, 'the roster lists the proposal writer');
    foreach (array_merge(...array_column($c, 'staff')) as $m) {
        pm_t_assert($m['on'] || $m['why'] !== '', $m['name'] . ' is on duty, or says why not');
    }
    // a business added in-app has no proposals; with no AI key nobody who writes is on duty
    $custom = pm_home_staff('promanaged', ['sales' => ['enabled' => ['scout' => true, 'writer' => true, 'proposals' => true]], 'ops' => ['ai' => false], 'social' => ['ready' => false, 'planned' => 0]]);
    pm_t_assert(array_sum(array_map(fn($m) => (int)$m['on'], $custom['sales'])) === 0 && str_contains($custom['sales'][0]['why'], 'AI key'), 'without an AI key nobody in sales is on duty, and the reason is the key');
    $on = pm_home_staff('promanaged', ['sales' => ['enabled' => ['scout' => true, 'writer' => false]], 'ops' => ['ai' => true], 'social' => ['ready' => true, 'planned' => 1, 'autopilot' => 'off']]);
    pm_t_assert($on['sales'][0]['on'] && !$on['sales'][4]['on'] && str_contains($on['sales'][4]['why'], 'switched off') && !$on['social'][1]['on'] && str_contains($on['social'][1]['why'], 'autopilot'), 'an agent switched off in settings, and autopilot off, are shown as off with the reason');
});

t('The activity feed is each business\'s own', function () {
    pm_save('agent_log', [
        ['at' => '2026-10-11 09:00:00', 'agent' => 'Scout', 'msg' => 'Found 3 new businesses'],
        ['at' => '2026-10-11 08:50:00', 'agent' => 'Writer', 'msg' => '[Travel Malawi] Drafted 2 emails'],
        ['at' => '2026-10-11 08:40:00', 'agent' => 'Mailer', 'msg' => 'Sending failed for Biz d'],
    ]);
    $p = pm_home_activity('promanaged');
    pm_t_assert(count($p) === 2 && $p[0]['agent'] === 'Scout' && $p[1]['error'] === true && $p[0]['error'] === false, 'ProManaged sees the lines with no business prefix, errors marked');
    $tm = pm_home_activity('travel');
    pm_t_assert(count($tm) === 1 && $tm[0]['msg'] === 'Drafted 2 emails', 'Travel Malawi sees its own lines without the prefix');
    pm_t_eq(count(pm_home_activity('promanaged', 1)), 1, 'the number of lines can be limited');
});

t('The overview screen', function () {
    pm_brand_set('promanaged');
    $vb = 'promanaged';
    $settings = pm_settings();
    $GLOBALS['PM_WHO'] = 'Chimwemwe';
    $GLOBALS['PM_DEPT'] = 'hq';
    ob_start();
    require dirname(__DIR__) . '/lib/view_home.php';
    $h = ob_get_clean();
    pm_t_assert(!preg_match('/(Warning|Fatal error|Notice|Deprecated):/', $h), 'it renders without a PHP warning');
    pm_t_assert(str_contains($h, 'class="hq-hero"') && str_contains($h, 'Chimwemwe') && str_contains($h, 'class="hq-kpis"') && substr_count($h, 'class="hq-kpi ') === 6, 'a greeting by name and six numbers');
    pm_t_assert(str_contains($h, 'Clear the drafts first.') && str_contains($h, 'Send two drafts') && str_contains($h, 'Today&#039;s brief') || str_contains($h, "Today's brief"), 'today\'s brief from the Director is shown');
    pm_t_assert(substr_count($h, 'class="dept"') === 5 && str_contains($h, 'Sales department') && str_contains($h, 'Brand and creative department') && str_contains($h, 'Insights department') && str_contains($h, 'Operations'), 'one card per department');
    pm_t_assert(str_contains($h, 'class="hq-needrow"') && str_contains($h, 'on duty') && str_contains($h, 'class="roster"'), 'what needs the owner, and the team roster, are there');
    pm_t_assert(str_contains($h, 'Your businesses') && str_contains($h, 'Travel Malawi') && str_contains($h, 'Found 3 new businesses') && str_contains($h, 'class="err"'), 'every business at a glance, and the team\'s diary with the failure marked');
    pm_t_assert(preg_match_all('/href="(\?tab=[^"]+)"/', $h, $m) > 10 && !array_filter($m[1], fn($u) => !preg_match('/^\?tab=[a-z]+(&amp;[a-z]+=[A-Za-z0-9_]+)*(#[a-z]+)?$/', str_replace('&', '&amp;', html_entity_decode($u)))), 'every link points inside the app');
    // nothing written yet: empty states, no warnings
    foreach (['leads', 'social_posts', 'agent_log', 'director'] as $f) {
        pm_save($f, []);
    }
    ob_start();
    require dirname(__DIR__) . '/lib/view_home.php';
    $h = ob_get_clean();
    pm_t_assert(!preg_match('/(Warning|Fatal error|Notice|Deprecated):/', $h) && str_contains($h, 'No strategy has been written yet') && str_contains($h, 'Nothing yet'), 'with nothing stored it shows the empty states, not errors');
});

pm_t_done();
