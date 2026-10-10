<?php
/**
 * Tests for finding contact names, emails and WhatsApp numbers: the business's own website read first (details by code, the owner's name only when the AI points to a sentence that is
 * really on the page), the web search as a second chance, what is applied to a lead, and the details an owner types or confirms on the card.
 * Runs on a TEMP COPY of data/ with the AI and the web stubbed; nothing is sent. Run: php tests/test_contacts.php
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

/** A small website: url => html. */
$SITE = [
    'https://www.mgozalodge.test/' => '<html><head><title>Mgoza Lodge</title></head><body><nav><a href="/about-us/our-team.html">Our team</a> <a href="/contact">Contact</a> <a href="/contact/">Contact again</a> <a href="/privacy">Privacy</a> <a href="/rooms">Rooms</a></nav>
        <p>Mgoza Lodge is a small lodge on the lake shore near Cape Maclear with six rooms.</p><p>Chat to us on <a href="https://wa.me/265995632105">WhatsApp</a>.</p></body></html>',
    'https://www.mgozalodge.test/about-us/our-team.html' => '<html><body><h1>Our team</h1><p>Alan Joffe ~ Owner</p><p>Grace Banda is our head chef in the restaurant kitchen.</p><p>Mgoza Lodge was started in 2009 by the Joffe family.</p></body></html>',
    'https://www.mgozalodge.test/contact' => '<html><body><p>Write to us at <a href="mailto:info@mgozalodge.test">info@mgozalodge.test</a> or call +265 995 632 105. Our web designer is design@webagency.example.</p></body></html>',
];
$GLOBALS['PM_PAGE_STUB'] = function (string $u) use (&$SITE, &$FETCHED) {
    $FETCHED[] = $u;
    return $SITE[$u] ?? $SITE[rtrim($u, '/') . '/'] ?? ($SITE[rtrim($u, '/')] ?? null);
};
$FETCHED = [];

t('Is it a person\'s name?', function () {
    foreach (['Alan Joffe', 'Grace Phiri', 'Moses', 'Dr Banda'] as $n) {
        pm_t_assert(pm_contact_name_ok($n), "$n is");
    }
    foreach (['Reservations', 'Owner', 'General Manager', '+265 888 22 66 65', 'info@lodge.mw', 'https://x.mw', 'X', '', 'Front Office'] as $n) {
        pm_t_assert(!pm_contact_name_ok($n), '"' . $n . '" is not');
    }
});

t('WhatsApp numbers on a page', function () {
    $html = '<a href="https://wa.me/265995632105">Chat</a> <a href="https://api.whatsapp.com/send?phone=265 999 953 235&text=hi">x</a> <a href="whatsapp://send?phone=+265888123456">y</a>
        <p>WhatsApp: 0991 792 311</p><p>WhatsApp us on 01 234 567 for landline</p><a href="tel:+265995632105">call</a>';
    $got = pm_page_whatsapp($html);
    pm_t_eq($got, ['265995632105', '265888123456', '265991792311'], 'links and a number written next to WhatsApp, as digits with the country code (a duplicate, a landline and a call link are not counted)');
    pm_t_eq(pm_page_whatsapp('<p>No chat here. Call +265 995 632 105.</p>'), [], 'a phone number alone is not WhatsApp');
});

t('Reading the business\'s own website', function () use (&$SITE, &$FETCHED) {
    $FETCHED = [];
    $s = pm_contact_site('www.mgozalodge.test');
    pm_t_eq(array_map(fn($p) => parse_url($p['url'], PHP_URL_PATH) ?: '/', $s['pages']), ['/', '/about-us/our-team.html', '/contact'], 'the home page, then the team page, then the contact page (read once, not twice; privacy and rooms are skipped)');
    pm_t_eq($s['emails'], ['info@mgozalodge.test', 'design@webagency.example'], 'emails on their own domain come first');
    pm_t_eq([$s['phones'], $s['whatsapp']], [['+265 995 632 105'], ['265995632105']], 'a phone number and the WhatsApp link');
    pm_t_eq(pm_contact_site('')['pages'], [], 'no website given: nothing');
    $e = pm_contact_site('https://down.test');
    pm_t_assert($e['pages'] === [] && str_contains($e['note'], 'did not open'), 'a site that will not open gives a note: ' . $e['note']);
});

t('The owner is named only by a sentence that is really on the page', function () use (&$SITE) {
    $site = pm_contact_site('www.mgozalodge.test');
    $lead = ['name' => 'Mgoza Lodge'];
    $ask = function (array $people) use ($lead, $site) {
        $GLOBALS['PM_AI_STUB'] = fn($sys, $user) => json_encode(['people' => $people]);
        return pm_contact_people($lead, $site);
    };
    $p = $ask([['name' => 'Alan Joffe', 'role' => 'Owner', 'quote' => 'Alan Joffe ~ Owner']]);
    pm_t_eq([$p['name'] ?? '', $p['title'] ?? '', $p['source'] ?? ''], ['Alan Joffe', 'Owner', 'https://www.mgozalodge.test/about-us/our-team.html'], 'a named owner with the page that names them');
    pm_t_eq($ask([['name' => 'Alan Joffe', 'role' => 'Owner', 'quote' => 'Alan Joffe has owned this lodge since the day it opened']]), [], 'a sentence that is not on any page is refused');
    pm_t_eq($ask([['name' => 'Peter Mwale', 'role' => 'Owner', 'quote' => 'Alan Joffe ~ Owner']]), [], 'a name that is not in the sentence is refused');
    pm_t_eq($ask([['name' => 'Grace Banda', 'role' => 'Head chef, restaurant kitchen', 'quote' => 'Grace Banda is our head chef in the restaurant kitchen']]), [], 'kitchen and restaurant staff are not who we write to');
    pm_t_eq($ask([['name' => 'Grace Banda', 'role' => 'Guest', 'quote' => 'Grace Banda is our head chef in the restaurant kitchen']]), [], 'someone who is not the owner or a manager is refused');
    pm_t_eq($ask([['name' => 'Reservations', 'role' => 'Manager', 'quote' => 'Alan Joffe ~ Owner']]), [], 'a department is not a person');
    $p = $ask([['name' => 'Grace Banda', 'role' => 'Manager', 'quote' => 'Grace Banda is our head chef in the restaurant kitchen'], ['name' => 'Alan Joffe', 'role' => 'Owner', 'quote' => 'Alan Joffe ~ Owner']]);
    pm_t_eq($p['name'] ?? '', 'Alan Joffe', 'the owner is preferred to a manager');
    $GLOBALS['PM_AI_STUB'] = fn() => 'no json';
    pm_t_eq(pm_contact_people($lead, $site), [], 'a reply that is not JSON names nobody');
    $GLOBALS['PM_AI_STUB'] = function () {
        throw new RuntimeException('down');
    };
    pm_t_eq(pm_contact_people($lead, $site), [], 'a failing AI names nobody');
    pm_t_eq(pm_contact_people($lead, ['pages' => []]), [], 'and with no pages it is not even asked');
    $GLOBALS['PM_AI_STUB'] = null;
});

t('The Contact Finder: website first, the web search only for what is still missing', function () {
    $calls = ['people' => 0, 'search' => 0];
    $GLOBALS['PM_AI_STUB'] = function ($sys, $user) use (&$calls) {
        if (str_contains($sys, 'You read pages from the website')) {
            $calls['people']++;
            return json_encode(['people' => [['name' => 'Alan Joffe', 'role' => 'Owner', 'quote' => 'Alan Joffe ~ Owner']]]);
        }
        $calls['search']++;
        return json_encode(['contact' => 'Searched Person', 'contact_title' => 'Manager', 'email' => 'searched@elsewhere.example', 'phone' => '0999000111', 'whatsapp' => '', 'website' => '', 'source' => 'https://facebook.com/x']);
    };
    $lead = ['id' => 'c1', 'name' => 'Mgoza Lodge', 'type' => 'lodge', 'city' => 'Mangochi', 'website' => 'www.mgozalodge.test', 'email' => 'info@mgozalodge.test', 'phone' => '', 'contact' => ''];
    $d = pm_agent_contact($lead);
    pm_t_eq([$d['contact'], $d['contact_title'], $d['source'], $d['whatsapp'], $d['phone']], ['Alan Joffe', 'Owner', 'https://www.mgozalodge.test/about-us/our-team.html', '+265995632105', '+265 995 632 105'], 'the name, its page, the WhatsApp number and the phone all come from their own site');
    pm_t_eq($calls, ['people' => 1, 'search' => 0], 'with a name and an email in hand the web search is not paid for');
    $calls = ['people' => 0, 'search' => 0];
    $d = pm_agent_contact(['email' => '', 'contact' => ''] + $lead);
    pm_t_eq([$d['email'], $calls['search']], ['info@mgozalodge.test', 0], 'an email found on the site is taken, and so no search is needed');
    $calls = ['people' => 0, 'search' => 0];
    $d = pm_agent_contact(['website' => '', 'email' => '', 'contact' => ''] + $lead);
    pm_t_eq([$d['contact'], $d['email'], $d['phone'], $d['source'], $calls], ['Searched Person', 'searched@elsewhere.example', '0999000111', 'https://facebook.com/x', ['people' => 0, 'search' => 1]], 'no website: the web search gives what it can, with the page it names as its source');
    $calls = ['people' => 0, 'search' => 0];
    $lead2 = ['website' => 'www.nowhere.test', 'email' => '', 'contact' => ''] + $lead;
    $d = pm_agent_contact($lead2);
    pm_t_assert($calls['search'] === 1 && $d['contact'] === 'Searched Person', 'a site that will not open is not the end: the search runs');
    $GLOBALS['PM_AI_STUB'] = function ($sys) {
        if (str_contains($sys, 'You read pages')) {
            return json_encode(['people' => []]);
        }
        throw new RuntimeException('search down');
    };
    $d = pm_agent_contact(['contact' => ''] + $lead);
    pm_t_assert($d['whatsapp'] === '+265995632105' && $d['email'] === 'info@mgozalodge.test', 'if the search fails but the site gave details, those are kept');
    $threw = false;
    try {
        pm_agent_contact(['website' => 'www.nowhere.test', 'email' => '', 'contact' => ''] + $lead);
    } catch (Throwable) {
        $threw = true;
    }
    pm_t_assert($threw, 'if the site gave nothing and the search failed too, the caller is told');
    $GLOBALS['PM_AI_STUB'] = null;
});

t('What is applied to a lead', function () {
    $lead = ['id' => 'a1', 'name' => 'Mgoza Lodge', 'website' => 'https://www.mgozalodge.test', 'email' => '', 'phone' => '', 'whatsapp' => '', 'contact' => '', 'email_bad' => 'old@x.mw', 'notes' => []];
    $added = pm_contact_apply($lead, ['contact' => 'Alan Joffe', 'contact_title' => 'Owner', 'source' => 'https://www.mgozalodge.test/about-us/our-team.html', 'email' => 'info@mgozalodge.test', 'phone' => '+265 995 632 105', 'whatsapp' => '+265995632105']);
    pm_t_eq([$lead['contact'], $lead['contact_title'], $lead['email'], $lead['phone'], $lead['whatsapp'], isset($lead['email_bad']), $lead['contact_checked'] === date('Y-m-d')], ['Alan Joffe', 'Owner', 'info@mgozalodge.test', '+265 995 632 105', '+265995632105', false, true], 'a name from their own page is the contact; the details fill the empty boxes; a bounced address is forgotten');
    pm_t_eq($added, ['an email address', 'a phone number', 'a WhatsApp number', "the owner or manager's name (Alan Joffe)"], 'and it says what was added');
    $l2 = $lead;
    $a2 = pm_contact_apply($l2, ['contact' => 'Someone Else', 'email' => 'other@x.mw', 'phone' => '0888', 'whatsapp' => '+265888999000']);
    pm_t_eq([$l2['contact'], $l2['email'], $l2['phone'], $l2['whatsapp'], $a2], ['Alan Joffe', 'info@mgozalodge.test', '+265 995 632 105', '+265995632105', []], 'nothing that is already there is replaced');
    $l3 = ['id' => 'a3', 'name' => 'X Lodge', 'website' => 'https://www.xlodge.test', 'email' => '', 'phone' => '', 'whatsapp' => '', 'contact' => '', 'notes' => []];
    $a3 = pm_contact_apply($l3, ['contact' => 'Luca Oddera', 'contact_title' => 'Owner', 'source' => 'https://blog.example/post', 'email' => 'not an email', 'whatsapp' => '01 234 567']);
    pm_t_assert($l3['contact'] === '' && str_contains($l3['contact_hint'], 'Luca Oddera') && $l3['email'] === '' && $l3['whatsapp'] === '', 'a name from someone else\'s page is only a possible name; a bad email and a landline are not taken');
    pm_t_eq($a3, ['a possible name to confirm (Luca Oddera (Owner), from https://blog.example/post)'], 'and it says so');
});

t('One lead, on demand', function () {
    $GLOBALS['PM_AI_STUB'] = function ($sys, $user) {
        if (str_contains($sys, 'You read pages')) {
            return json_encode(['people' => [['name' => 'Alan Joffe', 'role' => 'Owner', 'quote' => 'Alan Joffe ~ Owner']]]);
        }
        return json_encode(['contact' => '', 'email' => '', 'phone' => '', 'whatsapp' => '', 'website' => '', 'source' => '']);
    };
    pm_save('leads', [
        'o1' => ['id' => 'o1', 'brand' => 'promanaged', 'name' => 'Mgoza Lodge', 'type' => 'lodge', 'city' => 'Mangochi', 'status' => 'drafted', 'website' => 'www.mgozalodge.test', 'email' => '', 'phone' => '', 'whatsapp' => '', 'contact' => '', 'notes' => [], 'thread' => [], 'sent' => []],
        'o2' => ['id' => 'o2', 'brand' => 'promanaged', 'name' => 'Quiet Lodge', 'type' => 'lodge', 'city' => 'Zomba', 'status' => 'drafted', 'website' => '', 'email' => '', 'phone' => '', 'whatsapp' => '', 'contact' => '', 'notes' => [], 'thread' => [], 'sent' => []],
    ]);
    [$ok, $msg] = pm_contact_find_one('o1');
    $l = pm_leads()['o1'];
    pm_t_assert($ok && $l['contact'] === 'Alan Joffe' && $l['email'] === 'info@mgozalodge.test' && $l['whatsapp'] === '+265995632105', 'found and filled: ' . $msg);
    pm_t_assert(str_contains($msg, 'an email address') && str_contains($msg, 'Alan Joffe') && str_contains($msg, 'read 3 pages of their website'), 'the message says what was found and how it looked');
    pm_t_assert(str_contains(json_encode($l['notes']), 'Contact finder added'), 'and the lead carries a note');
    [$ok, $msg] = pm_contact_find_one('o2');
    pm_t_assert($ok && str_contains($msg, 'Nothing new found') && str_contains($msg, 'no website on file'), 'a lead with nothing to find says so plainly: ' . $msg);
    [$ok, $msg] = pm_contact_find_one('nobody');
    pm_t_assert(!$ok && str_contains($msg, 'not found'), 'an unknown lead is refused');
    $GLOBALS['PM_AI_STUB'] = function () {
        throw new RuntimeException('AI down');
    };
    pm_save('leads', ['o3' => ['id' => 'o3', 'brand' => 'promanaged', 'name' => 'Far Lodge', 'type' => 'lodge', 'city' => 'Zomba', 'status' => 'drafted', 'website' => '', 'email' => '', 'contact' => '', 'notes' => [], 'thread' => [], 'sent' => []]]);
    [$ok, $msg] = pm_contact_find_one('o3');
    pm_t_assert(!$ok && str_contains($msg, 'failed') && str_contains($msg, 'type what you know'), 'a failing search says so and points to the boxes: ' . $msg);
    $GLOBALS['PM_AI_STUB'] = null;
});

t('Details typed on the card', function () {
    pm_save('leads', ['s1' => ['id' => 's1', 'brand' => 'promanaged', 'name' => 'Lodge S', 'status' => 'drafted', 'email' => '', 'email_bad' => 'bad@x.mw', 'phone' => '', 'whatsapp' => '', 'contact' => '', 'contact_hint' => 'Moses (manager)', 'notes' => [], 'thread' => [], 'sent' => []]]);
    [$ok, $m] = pm_contact_save('s1', ['email' => 'not-an-email']);
    pm_t_assert(!$ok && pm_leads()['s1']['email'] === '', 'an email that is not one is refused and nothing is saved: ' . $m);
    [$ok, $m] = pm_contact_save('s1', ['whatsapp' => 'call me']);
    pm_t_assert(!$ok, 'text that is not a number is refused');
    [$ok, $m] = pm_contact_save('s1', ['contact' => 'Reservations']);
    pm_t_assert(!$ok && pm_leads()['s1']['contact'] === '', 'a department is not saved as a person');
    [$ok, $m] = pm_contact_save('s1', ['email' => ' Info@Lodge-S.MW ']);
    $l = pm_leads()['s1'];
    pm_t_assert($ok && $l['email'] === 'info@lodge-s.mw' && !isset($l['email_bad']) && $l['email_src'] === 'owner' && str_contains($m, 'mail saved'), 'a good email is saved in lower case, a bounce is forgotten, and it is marked as given by you: ' . $m);
    [$ok, $m] = pm_contact_save('s1', ['whatsapp' => '01 234 567']);
    $l = pm_leads()['s1'];
    pm_t_assert($ok && $l['whatsapp'] === '' && $l['phone'] === '01 234 567' && str_contains($m, 'landline'), 'a landline typed as WhatsApp is kept as the phone, and says so: ' . $m);
    [$ok, $m] = pm_contact_save('s1', ['whatsapp' => '0999 123 456']);
    pm_t_eq([$ok, pm_leads()['s1']['whatsapp'], pm_wa_best(pm_leads()['s1'])[0]], [true, '+265999123456', '265999123456'], 'a mobile is saved with the country code and works for WhatsApp');
    [$ok, $m] = pm_contact_save('s1', ['contact' => 'Alan Joffe']);
    $l = pm_leads()['s1'];
    pm_t_assert($ok && $l['contact'] === 'Alan Joffe' && !isset($l['contact_hint']), 'a name is saved and the possible name is dropped');
    pm_t_assert(!pm_contact_save('nobody', ['email' => 'a@b.mw'])[0], 'an unknown lead is refused');
});

t('A possible name the owner confirms', function () {
    $h = fn(string $x) => pm_contact_hint_parts(['contact_hint' => $x]);
    pm_t_eq($h('Luca Oddera (Owner), from https://annosafrica.org.uk/x'), ['name' => 'Luca Oddera', 'title' => 'Owner', 'source' => 'https://annosafrica.org.uk/x', 'not_owner' => false], 'name, role and where it came from');
    pm_t_eq($h('Moses (restaurant manager), not the owner')['not_owner'] ?? null, true, 'a name marked "not the owner" is flagged');
    pm_t_eq($h('+265 888 22 66 65 (Reservations / Management)'), [], 'a phone number is not a name');
    pm_t_eq($h(''), [], 'nothing is nothing');
    pm_save('leads', ['h1' => ['id' => 'h1', 'brand' => 'promanaged', 'name' => 'Hint Lodge', 'status' => 'drafted', 'contact' => '', 'contact_hint' => 'Frederik (Co-owner), from https://blog.example/post', 'notes' => [], 'thread' => [], 'sent' => []]]);
    [$ok, $m] = pm_contact_use_hint('h1');
    $l = pm_leads()['h1'];
    pm_t_assert($ok && $l['contact'] === 'Frederik' && $l['contact_title'] === 'Co-owner' && !isset($l['contact_hint']) && str_contains(json_encode($l['notes']), 'confirmed by you'), 'confirming makes it the contact, with its role, and leaves a note: ' . $m);
    pm_t_assert(!pm_contact_use_hint('h1')[0] && !pm_contact_use_hint('nobody')[0], 'with nothing to confirm, or no such lead, it is refused');
});

pm_t_done();
