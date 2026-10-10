<?php
/**
 * Tests for adding a business: the questionnaire, the registry, and the proof that a third business never speaks as ProManaged IT or Travel Malawi.
 * Runs on a TEMP COPY of data/ (see boot.php) with mail, WhatsApp and the AI stubbed: nothing real is contacted, nothing real is written.
 * Run: php tests/test_brands.php
 */
require __DIR__ . '/boot.php';
$T = pm_test_boot();
require_once dirname(__DIR__) . '/lib/wa_biz.php'; // brings engage.php, director.php
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

/** Words that must never reach a third business's prompts, copy or messages. */
const LEAK = '/ProManaged|Travel Malawi|travel-malawi|Stay Onboarding|Business Management System|website check|CHECK on WhatsApp|HOST on WhatsApp|IT support|Hotel Starter|Rosalyn|Liwonde Sun|itsupportmalawi|SmallBusinessMalawi/i';

$ANS = ['name' => 'Sunrise Solar', 'sells' => 'We design, install and maintain solar power and battery systems for homes and small businesses.',
    'customers' => 'Homeowners and small business owners who want reliable power', 'sell_to' => 'business', 'cities' => 'Lilongwe, Blantyre',
    'targets' => 'schools, clinics, guest houses', 'facts' => "We install and maintain solar systems\nWe offer a free site visit", 'voice' => 'friendly', 'magnet' => 'a free site visit',
    'email' => 'info@sunrise.example', 'phone' => '0999111222', 'website' => 'https://www.sunrise.example'];

/** Records every prompt; answers by agent so each call gets a usable reply. */
$SEEN = [];
$stub = function (string $sys, string $user) use (&$SEEN): string {
    $SEEN[] = $sys . "\n" . $user;
    if (str_contains($sys, 'You are the Scout') || str_contains($sys, 'You are the Watcher')) {
        return json_encode([['name' => 'Bright Schools', 'type' => 'school', 'city' => 'Lilongwe', 'email' => 'office@brightschools.mw', 'website' => 'https://brightschools.mw', 'evidence' => ['Their site lists 3 campuses https://brightschools.mw'], 'need_signals' => ['running on diesel generators'], 'offering' => '']]);
    }
    if (str_contains($sys, 'You are the Qualifier')) {
        return json_encode([['id' => 'x1', 'score' => 70, 'offering' => 'Solar installation', 'pain' => 'power cuts', 'reason' => 'runs on diesel', 'skip' => false]]);
    }
    if (str_contains($sys, 'You are the Researcher') && !str_contains($sys, 'precision is your job')) {
        return json_encode(['found' => true, 'name' => 'Bright Schools', 'type' => 'school', 'city' => 'Lilongwe', 'evidence' => ['site https://brightschools.mw'], 'score' => 60, 'offering' => 'Solar installation', 'pain' => 'power cuts', 'reason' => 'x']);
    }
    if (str_contains($sys, 'You are writing outreach') || str_contains($sys, 'You are the Follow-up agent')) {
        return json_encode([['id' => 'x1', 'email_subject' => 'Power at Bright Schools', 'email_body' => 'Hello, I came across Bright Schools while looking at schools in Lilongwe.', 'whatsapp' => 'Hello, quick question about power at your school.']]);
    }
    if (str_contains($sys, 'You write one')) {
        return json_encode(['subject' => 'Power cuts', 'body' => 'Hello, a short note about power cuts at your school. Would a short call suit you?']);
    }
    if (str_contains($sys, 'social media manager') || str_contains($sys, 'You write short social posts')) {
        return json_encode(['posts' => []]);
    }
    if (str_contains($sys, 'Trend radar')) {
        return json_encode(['items' => ['Fuel prices are up this week']]);
    }
    if (str_contains($sys, 'Partnership radar')) {
        return json_encode(['items' => []]);
    }
    return '{}';
};

/* =========================================================== registry */

t('Only the two original businesses exist at first', function () {
    pm_t_eq(pm_brand_ids(), ['promanaged', 'travel'], 'the registry starts with the two built-ins');
    pm_t_eq(pm_brand_norm('nobody'), 'promanaged', 'an unknown id is treated as ProManaged IT, as it always was');
    pm_t_eq([pm_brand_is_custom('travel'), pm_brand_valid('travel'), pm_brand_name('travel'), pm_brand_env_prefix('travel'), pm_brand_env_prefix('promanaged')], [false, true, 'Travel Malawi', 'TM_', ''], 'built-ins keep their names and env prefixes');
    pm_brand_set('nobody');
    pm_t_eq(pm_brand(), 'promanaged', 'pm_brand_set never lands on an unknown business');
});

t('The questionnaire needs three answers and refuses lookalikes', function () use ($ANS) {
    pm_t_eq(pm_brand_check_answers($ANS), [], 'a complete set of answers passes');
    pm_t_assert(count(pm_brand_check_answers([])) === 3, 'name, what it sells and who buys are all required');
    pm_t_assert((bool)pm_brand_check_answers(['name' => 'ProManaged IT'] + $ANS), 'an existing business name is refused');
    pm_t_assert((bool)pm_brand_check_answers(['name' => 'Travel  Malawi'] + $ANS), 'so is the other one, in any spacing');
    pm_t_assert((bool)pm_brand_check_answers(['email' => 'not-an-email'] + $ANS), 'a bad email address is refused');
    pm_t_assert((bool)pm_brand_check_answers(['sells' => 'solar'] + $ANS), 'one word is not an answer');
});

t('The draft: AI helps but may only restate what the owner said', function () use ($ANS) {
    $seen = [];
    $GLOBALS['PM_AI_STUB'] = function ($sys, $user) use (&$seen) {
        $seen[] = $sys . $user;
        return json_encode(['about' => 'Sunrise Solar designs, installs and maintains solar systems.', 'offerings' => ['Solar installation: panels and batteries for homes and small businesses'],
            'sectors' => ['hospitals', 'factories'], 'facts' => ['They have installed over 500 systems', 'The team installs and maintains solar systems'], 'audience' => 'Homeowners and small businesses',
            'cta_keyword' => 'solar quote!', 'pillars' => [['name' => 'Tip/How-to', 'weight' => 40], ['name' => 'Proof', 'weight' => 30], ['name' => 'Offer', 'weight' => 30]], 'cities' => ['Mzuzu']]);
    };
    $r = pm_brand_draft($ANS);
    $d = $r['draft'];
    pm_t_assert($r['ai'] === true && $seen !== [], 'the AI was asked once');
    pm_t_assert(!str_contains(json_encode($d['facts']), '500'), 'a fact with a number the owner never wrote is dropped');
    pm_t_assert(in_array('We offer a free site visit', $d['facts'], true) && in_array('The team installs and maintains solar systems', $d['facts'], true), 'the owner\'s own facts stay, and a safe restatement is kept');
    pm_t_eq($d['sectors'], ['schools', 'clinics', 'guest houses'], 'kinds to look for the owner typed are not replaced by the AI');
    pm_t_eq($d['cities'], ['Lilongwe', 'Blantyre'], 'neither are the cities');
    pm_t_eq($d['cta_keyword'], 'SOLARQUOTE', 'the WhatsApp keyword is reduced to capital letters');
    pm_t_eq(array_column($d['pillars'], 'name'), ['Tip/How-to', 'Proof', 'Offer'], 'content themes come from the AI');
    // without the AI
    $GLOBALS['PM_AI_STUB'] = null;
    $nr = pm_brand_draft(['targets' => '', 'cities' => ''] + $ANS);
    pm_t_assert($nr['ai'] === false && $nr['note'] !== '' && $nr['draft']['sectors'] === [] && str_contains($nr['note'], 'kinds of business'), 'with no AI the draft is plain and says what is missing: ' . $nr['note']);
    pm_t_eq($nr['draft']['facts'], ['We install and maintain solar systems', 'We offer a free site visit'], 'plain facts are exactly the owner\'s lines');
    pm_t_assert($nr['draft']['voice'] === PM_BRAND_VOICES['friendly'] && count($nr['draft']['pillars']) === 5, 'voice and a starter set of themes are filled in');
});

/* =========================================================== create */

$CREATED = '';
t('Creating a business stores it apart from the others', function () use ($ANS, &$CREATED) {
    $GLOBALS['PM_AI_STUB'] = null;
    $draft = pm_brand_draft($ANS)['draft'];
    $draft['sectors'] = ['schools', 'clinics', 'guest houses'];
    [$id, $err] = pm_brand_create($ANS, $draft);
    $CREATED = $id;
    pm_t_eq([$id, $err], ['sunrisesolar', []], 'the business gets a short id from its name');
    pm_t_eq(pm_brand_ids(), ['promanaged', 'travel', 'sunrisesolar'], 'it joins the registry');
    pm_t_eq([pm_brand_is_custom($id), pm_brand_norm($id), pm_brand_env_prefix($id), pm_brand_name($id)], [true, $id, 'SUNRISESOLAR_', 'Sunrise Solar'], 'it is recognised everywhere');
    [$id2] = pm_brand_create($ANS, $draft);
    pm_t_eq($id2, 'sunrisesolar2', 'a second business with the same name gets its own id');
    pm_brand_archive($id2);
    pm_t_eq(pm_brand_ids(), ['promanaged', 'travel', 'sunrisesolar'], 'an archived business leaves the menus and the schedule');
    foreach (['Travel', 'New', 'Default'] as $nm) {
        pm_t_assert(!in_array(pm_brand_slug($nm), PM_BRAND_RESERVED, true), "\"$nm\" never becomes a reserved id");
    }
    $raw = pm_load('settings', 'pm_default_settings');
    pm_t_assert(isset($raw['brands'][$id]) && !isset($raw['brands'][$id]['smtp']['password']) || $raw['brands'][$id]['smtp']['password'] === '', 'its block is in settings, with no password yet');
    $cfg = pm_load('agents_config', 'pm_agents_default_config');
    pm_t_eq($cfg[$id]['sectors'], ['schools', 'clinics', 'guest houses'], 'its targets are in the agents config');
    pm_t_assert($cfg[$id]['enabled']['proposals'] === false && $cfg[$id]['proposals_per_day'] === 0, 'proposals are off: they use ProManaged IT\'s own wording');
    pm_t_assert(in_array('Rosalyn\'s Beach Hotel', pm_agents_config('promanaged')['existing_clients'], true), 'ProManaged IT keeps its own client list untouched');
});

t('A business speaks as itself', function () use (&$CREATED) {
    $id = $CREATED;
    pm_brand_set($id);
    $s = pm_settings();
    pm_t_eq([$s['company_name'], $s['email'], $s['phone'], $s['website'], $s['signatory_title']], ['Sunrise Solar', 'info@sunrise.example', '0999111222', 'www.sunrise.example', 'Owner'], 'name and contact details are its own');
    pm_t_eq([$s['smtp']['from_email'], $s['smtp']['from_name'], $s['online_signing']], ['info@sunrise.example', 'Sunrise Solar', false], 'it sends as itself, with online signing off');
    pm_t_assert($s['ref_prefix'] === 'SUN' && $s['next_ref'] === 1, 'it has its own reference numbers');
    pm_brand_set('promanaged');
    pm_t_eq(pm_settings()['company_name'], 'ProManaged IT', 'ProManaged IT is untouched');
    pm_brand_set('travel');
    pm_t_eq(pm_settings()['company_name'], 'Travel Malawi', 'so is Travel Malawi');
    pm_brand_set('promanaged');
    $b = pm_brain($id);
    pm_t_assert(str_contains($b['about'], 'solar') && str_contains($b['facts'], 'free site visit') && $b['voice'] === PM_BRAND_VOICES['friendly'], 'its brain comes from the questionnaire');
    pm_brand_set($id);
    $brief = pm_agents_company_brief('full');
    pm_t_assert(!preg_match(LEAK, $brief) && str_contains($brief, 'Sunrise Solar') && str_contains($brief, 'What we offer'), 'the brief the agents read is about it alone');
    pm_brand_set('promanaged');
    $cfg = pm_agents_config($id);
    pm_t_assert($cfg['sectors'] === ['schools', 'clinics', 'guest houses'] && $cfg['cities'] === ['Lilongwe', 'Blantyre'] && $cfg['existing_clients'] === [] && $cfg['send_cap'] === 6, 'its targets and conservative limits');
    pm_t_assert(!preg_match(LEAK, implode(' ', $cfg['offerings'])), 'its offerings are its own');
    pm_t_assert(in_array('hotels and lodges', pm_agents_config('promanaged')['sectors'], true), 'ProManaged IT keeps its sectors');
    pm_t_eq(pm_imap_settings($id)['host'], '', 'no mailbox yet, so nothing is read');
    // a mailbox typed in its settings is used for sending and for reading replies
    $raw = pm_load('settings', 'pm_default_settings');
    $raw['brands'][$id]['smtp'] = ['host' => 'mail.sunrise.example', 'port' => 465, 'encryption' => 'ssl', 'username' => 'info@sunrise.example', 'password' => 'pw', 'from_email' => 'info@sunrise.example', 'from_name' => 'Sunrise Solar', 'bcc_self' => true];
    pm_save('settings', $raw);
    pm_brand_set($id);
    pm_t_eq([pm_settings()['smtp']['host'], pm_settings()['smtp']['password']], ['mail.sunrise.example', 'pw'], 'sending uses its own mailbox');
    pm_brand_set('promanaged');
    $im = pm_imap_settings($id);
    pm_t_eq([$im['host'], $im['user'], $im['pass']], ['mail.sunrise.example', 'info@sunrise.example', 'pw'], 'reading replies uses the same mailbox');
    pm_t_assert(in_array('info@sunrise.example', pm_own_addresses(), true), 'its own address is never mistaken for a customer reply');
});

/* =========================================================== no leaks */

t('Every agent prompt for a third business is free of the other two', function () use (&$CREATED, &$SEEN, $stub) {
    $id = $CREATED;
    $GLOBALS['PM_AI_STUB'] = $stub;
    pm_brand_set($id);
    $lead = ['id' => 'x1', 'brand' => $id, 'name' => 'Bright Schools', 'type' => 'school', 'city' => 'Lilongwe', 'contact' => '', 'website' => '', 'evidence' => ['Their site lists campuses'], 'social_gaps' => [], 'pain' => 'power cuts',
        'drafts' => ['email_body' => 'Hello'], 'status' => 'qualified', 'score' => 60];
    $SEEN = [];
    $out = pm_agent_scout('schools', 'Lilongwe', 3, ['Old School']);
    pm_t_eq(count($out), 1, 'the scout parses its answer');
    pm_agent_watch($id, []);
    pm_agent_qualify([$lead]);
    pm_agent_write([$lead]);
    pm_agent_followup([$lead]);
    pm_agent_lookup('Bright Schools', 'Lilongwe');
    pm_agent_compose($lead, 'email', 'human', 'short', 'need', 'english');
    pm_agent_compose($lead, 'whatsapp', 'warm', 'short', 'offer', 'english');
    pm_agent_winback([$lead + ['lost_reason' => 'not_now']]);
    pm_agent_postsign([$lead]);
    pm_agent_contact($lead);
    pm_t_assert(count($SEEN) >= 11, 'eleven agent calls were captured (' . count($SEEN) . ')');
    $bad = [];
    foreach ($SEEN as $p) {
        if (preg_match(LEAK, $p, $m)) {
            $bad[] = $m[0] . ' in: ' . substr($p, 0, 90);
        }
    }
    pm_t_eq($bad, [], 'no prompt mentions ProManaged IT, Travel Malawi, their packages or their products');
    pm_t_assert(count(array_filter($SEEN, fn($p) => str_contains($p, 'Sunrise Solar'))) === count($SEEN) - 1, 'every prompt names the business it works for (the Contact Finder only looks people up at the lead)');
    pm_t_assert(str_contains(implode(' ', $SEEN), 'free site visit'), 'the owner\'s own facts reach the agents');
    $quick = implode(' ', pm_wa_quick_replies());
    pm_t_assert(!preg_match(LEAK, $quick) && str_contains($quick, 'Sunrise Solar'), 'the paste-in WhatsApp replies are its own');
    pm_t_eq(pm_lead_id('Bright Schools', 'Lilongwe', $id) !== pm_lead_id('Bright Schools', 'Lilongwe', 'promanaged'), true, 'the same school is a different lead for each business');
    pm_t_eq(pm_lead_group(['brand' => $id, 'type' => 'a primary school']), 'Schools', 'leads are grouped by the business\'s own target types');
    pm_t_eq(pm_lead_group(['brand' => $id, 'type' => 'car wash']), 'Other', 'and anything else is Other');
});

t('Scouting plan, today list and the other businesses stay apart', function () use (&$CREATED) {
    $id = $CREATED;
    pm_brand_set($id);
    $cfg = pm_agents_config();
    $targets = pm_scout_targets($cfg);
    pm_t_assert($targets && in_array($targets[0][0], ['schools', 'clinics', 'guest houses'], true) && in_array($targets[0][1], ['Lilongwe', 'Blantyre'], true), 'scouts search its own types and cities: ' . json_encode($targets[0] ?? []));
    $mine = ['id' => 'm1', 'brand' => $id, 'name' => 'Bright Schools', 'type' => 'school', 'city' => 'Lilongwe', 'status' => 'drafted', 'score' => 80, 'email' => 'a@brightschools.mw', 'drafts' => ['email_body' => 'Hello'], 'sent' => [], 'notes' => [], 'thread' => []];
    $other = ['id' => 'o1', 'brand' => 'promanaged', 'name' => 'Sunbird Shop', 'type' => 'shop', 'city' => 'Blantyre', 'status' => 'drafted', 'score' => 90, 'email' => 'a@sunbird.mw', 'drafts' => ['email_body' => 'Hello'], 'sent' => [], 'notes' => [], 'thread' => []];
    $plan = pm_daily_plan(['m1' => $mine, 'o1' => $other], $cfg);
    $titles = implode(' | ', array_column($plan, 'title'));
    pm_t_assert(str_contains($titles, 'Bright Schools') && !str_contains($titles, 'Sunbird'), "today's list holds only its own leads");
    // an existing client of this business is never cold-pitched
    $raw = pm_load('agents_config', 'pm_agents_default_config');
    $raw[$id]['existing_clients'] = ['Bright Schools'];
    pm_save('agents_config', $raw);
    $plan = pm_daily_plan(['m1' => $mine], pm_agents_config());
    pm_t_assert(count(array_filter($plan, fn($t) => $t['kind'] === 'send')) === 0 && count(array_filter($plan, fn($t) => $t['kind'] === 'upsell')) === 1, 'its own client list is honoured: a check-in instead of a cold email');
    pm_t_assert(!str_contains(implode(' ', array_column($plan, 'title')), 'ProManaged'), 'and the wording does not name another business');
    $raw[$id]['existing_clients'] = [];
    pm_save('agents_config', $raw);
    pm_brand_set('promanaged');
});

t('Sending as the business: its address, its footer, the same safety checks', function () use (&$CREATED) {
    $id = $CREATED;
    $body = 'I came across Bright Schools while looking at schools in Lilongwe and wondered how you keep the lights on during power cuts. If that is a pain, there are one or two simple things that could help. Would a ten minute call suit you this week?';
    $lead = ['id' => 'm2', 'brand' => $id, 'name' => 'Bright Schools', 'type' => 'school', 'city' => 'Lilongwe', 'status' => 'drafted', 'score' => 80, 'email' => 'office@brightschools.mw', 'website' => '',
        'drafts' => ['email_subject' => 'Power at Bright Schools', 'email_body' => $body], 'sent' => [], 'notes' => [], 'thread' => [], 'approved_at' => date('Y-m-d H:i'), 'created' => date('Y-m-d H:i')];
    pm_ob_update('email_verify', function ($x) {
        $x['dns']['brightschools.mw'] = [true, time()];
        return $x;
    });
    pm_save('leads', ['m2' => $lead]);
    $leads = pm_leads();
    $sentTo = [];
    $mailer = function (array $s, array $m) use (&$sentTo) {
        $sentTo[] = [$s['company_name'], $s['smtp']['from_name'], $s['smtp']['from_email'], $m['body']];
        return [true, '', 'mid1'];
    };
    $r = pm_send_first($leads, 'm2', 'drafts', $mailer);
    pm_t_assert(!empty($r['ok']), 'a clean first email sends: ' . ($r['msg'] ?? ''));
    pm_t_eq([$sentTo[0][0], $sentTo[0][1], $sentTo[0][2]], ['Sunrise Solar', 'Sunrise Solar', 'info@sunrise.example'], 'it goes out from the business\'s own name and address');
    pm_t_assert(str_contains($sentTo[0][3], 'Sunrise Solar') && str_contains($sentTo[0][3], 'STOP') && !preg_match(LEAK, $sentTo[0][3]), 'the footer names it, carries STOP and names no one else');
    pm_t_eq(pm_brand(), 'promanaged', 'the app goes back to the business it was in');
    // STOP is honoured per company, whichever business wrote
    $stop = ['id' => 'm3', 'brand' => 'promanaged', 'name' => 'Brightside Ltd', 'status' => 'optout', 'email' => 'x@brightschools.mw', 'sent' => [], 'notes' => [], 'thread' => []];
    $leads = pm_leads();
    $leads['m3'] = $stop;
    $leads['m4'] = ['id' => 'm4', 'brand' => $id, 'name' => 'Bright Schools Annex', 'status' => 'drafted', 'email' => 'bursar@brightschools.mw', 'drafts' => ['email_subject' => 'Power', 'email_body' => $body], 'sent' => [], 'notes' => [], 'thread' => [], 'approved_at' => date('Y-m-d H:i')];
    pm_ob_update('email_verify', function ($x) {
        $x['dns']['brightschools.mw'] = [true, time()];
        return $x;
    });
    $box = [];
    $r = pm_send_first($leads, 'm4', 'drafts', fn($s, $m) => [true, '', 'm']);
    pm_t_assert(empty($r['ok']), 'a company that said STOP to another business is not contacted by this one either: ' . ($r['msg'] ?? ''));
});

t('Replies: neutral wording, no claim the owner did not write', function () use (&$CREATED) {
    $id = $CREATED;
    $r = pm_reply_template($id, 'How much does it cost?', 'Mary');
    pm_t_assert($r !== null && $r['intent'] === 'price' && !preg_match(LEAK, $r['text']) && !preg_match('/price each business individually/i', $r['text']), 'a price question gets a safe, neutral answer: ' . ($r['text'] ?? ''));
    pm_t_assert(pm_reply_template($id, 'Where are you?', '')['intent'] === 'location', 'location questions are answered too');
    pm_t_assert(pm_reply_lint('We will reply within 5 minutes', $id) !== [], 'the reply lint applies to it');
});

t('A WhatsApp message is answered as the business the lead belongs to', function () use (&$CREATED, &$SEEN) {
    $id = $CREATED;
    $pre = strtoupper($id) . '_';
    foreach (['' => '999', 'TM_' => '998', $pre => '997'] as $p => $pid) { // three businesses, three numbers
        putenv($p . 'WA_BIZ_TOKEN=tok');
        putenv($p . 'WA_BIZ_PHONE_ID=' . $pid);
        putenv($p . 'WA_BIZ_VERIFY=v');
    }
    $mk = fn($lid, $brand, $num) => ['id' => $lid, 'brand' => $brand, 'name' => "Lead $lid", 'type' => 'shop', 'city' => 'Zomba', 'status' => 'contacted', 'whatsapp' => $num, 'phone' => $num, 'score' => 70, 'sent' => ['2026-10-01 09:00'],
        'notes' => [], 'thread' => [['dir' => 'out', 'at' => date('Y-m-d H:i', time() - 7200), 'text' => 'Hello', 'ch' => 'wa']], 'wa_sent' => [date('Y-m-d H:i', time() - 7200)], 'email' => "l$lid@x$lid.mw"];
    pm_save('leads', ['w1' => $mk('w1', $id, '+265 999 700 001'), 'w2' => $mk('w2', 'travel', '+265 999 700 002'), 'w3' => $mk('w3', 'promanaged', '+265 999 700 003')]);
    $GLOBALS['PM_WA_STUB'] = fn($to, $text) => [true, 'wamid'];
    $systems = [];
    $GLOBALS['PM_AI_STUB'] = function ($sys, $user) use (&$systems) {
        $systems[] = $sys;
        return json_encode(['intent' => 'question', 'summary' => 'asks', 'needs_human' => false, 'reply_subject' => 'Re', 'reply_body' => 'Thanks for asking, we will reply.', 'whatsapp' => 'Thanks for asking.', 'resume_on' => '', 'next_step' => 'reply']);
    };
    pm_brand_set('promanaged');
    pm_wa_biz_handle('265999700001', 'Do you cover Zomba?', '997');
    pm_wa_biz_handle('265999700002', 'Do you have a room for Friday?', '998');
    pm_wa_biz_handle('265999700003', 'Do you do websites?', '999');
    $GLOBALS['PM_AI_STUB'] = null;
    $GLOBALS['PM_WA_STUB'] = null;
    pm_t_eq(count($systems), 3, 'three messages, three replies drafted');
    pm_t_assert(str_contains($systems[0], 'Reply agent for Sunrise Solar') && !preg_match(LEAK, $systems[0]), 'the third business\'s lead is answered by its own Reply agent, free of the other two');
    pm_t_assert(str_contains($systems[1], 'Reply agent for Travel Malawi') && str_contains($systems[2], 'Reply agent for ProManaged IT'), 'and the others by theirs');
    pm_t_eq(pm_brand(), 'promanaged', 'the app is back where it started');
    pm_save('leads', []);
    foreach (['', 'TM_', $pre] as $p) {
        foreach (['WA_BIZ_TOKEN', 'WA_BIZ_PHONE_ID', 'WA_BIZ_VERIFY'] as $k) {
            putenv($p . $k);
        }
    }
});

t('Every business has its own WhatsApp number and X account', function () use (&$CREATED) {
    $id = $CREATED;
    $pre = strtoupper($id) . '_';
    $set = function (string $p, string $pid) {
        putenv($p . 'WA_BIZ_TOKEN=tok' . $pid);
        putenv($p . 'WA_BIZ_PHONE_ID=' . $pid);
        putenv($p . 'WA_BIZ_VERIFY=verify' . $pid);
    };
    $clear = function () use ($pre) {
        foreach (['', 'TM_', $pre] as $p) {
            foreach (['WA_BIZ_TOKEN', 'WA_BIZ_PHONE_ID', 'WA_BIZ_VERIFY', 'WA_BIZ_TEMPLATE', 'X_API_KEY', 'X_API_SECRET', 'X_ACCESS_TOKEN', 'X_ACCESS_SECRET'] as $k) {
                putenv($p . $k);
            }
        }
    };
    $clear();
    pm_brand_set('promanaged');
    pm_t_eq(pm_wa_biz_brands(), [], 'nothing is connected to start with');
    $r0 = array_column(pm_setup_health(), null, 'key');
    pm_t_assert(str_contains($r0["wa_$id"]['add'], $pre . 'WA_BIZ_TOKEN') && str_contains($r0['wa_travel']['add'], 'TM_WA_BIZ_TOKEN') && str_contains($r0['wa_promanaged']['add'], 'WA_BIZ_TOKEN') && str_contains($r0["x_$id"]['add'], $pre . 'X_API_KEY'),
        'each Setup health row says which keys that business needs');
    $set('', '999');
    pm_t_eq(pm_wa_biz_brands(), ['promanaged'], 'ProManaged IT\'s keys connect ProManaged IT and nobody else');
    pm_t_assert(!pm_wa_biz_cfg('travel')['ready'] && !pm_wa_biz_cfg($id)['ready'], 'Travel Malawi and the added business do not borrow that number');
    [$ok, $why] = pm_wa_biz_send('265999000111', 'Hello', 'travel');
    pm_t_assert(!$ok && str_contains($why, 'TM_WA_BIZ_'), 'sending as a business with no number is refused and says which keys to add: ' . $why);
    [$ok, $why] = pm_wa_biz_send('265999000111', 'Hello', $id);
    pm_t_assert(!$ok && str_contains($why, $pre . 'WA_BIZ_'), 'the same for an added business: ' . $why);
    $set('TM_', '998');
    $set($pre, '997');
    pm_t_eq(pm_wa_biz_brands(), ['promanaged', 'travel', $id], 'each business with its own keys is connected');
    pm_t_eq(pm_wa_biz_brands('998'), ['travel'], 'a number id belongs to exactly one business');
    pm_t_eq(pm_wa_biz_brands('555'), [], 'and an unknown number id to none');
    pm_t_assert(pm_wa_biz_verify_ok('verify997') && pm_wa_biz_verify_ok('verify999') && !pm_wa_biz_verify_ok('nope') && !pm_wa_biz_verify_ok(''), 'Meta\'s check passes for any connected business\'s token, and for nothing else');

    $out = [];
    $GLOBALS['PM_WA_STUB'] = function ($to, $text, $pid = '') use (&$out) {
        $out[] = [$to, $pid];
        return [true, 'wamid'];
    };
    pm_wa_biz_send('265999000111', 'Hello', 'travel');
    pm_wa_biz_send('265999000111', 'Hello', $id);
    pm_wa_biz_send('265999000111', 'Hello', 'promanaged');
    pm_brand_set('travel');
    pm_wa_biz_send('265999000111', 'Hello');
    pm_brand_set('promanaged');
    pm_t_eq(array_column($out, 1), ['998', '997', '999', '998'], 'every message leaves from the number of its own business (and the current business is the default)');

    // an approved answer, a campaign message and a template go out from the lead's own business number too
    $out = [];
    $lead = ['id' => 'q1', 'brand' => $id, 'name' => 'Lead q1', 'status' => 'replied', 'whatsapp' => '+265 999 800 001', 'phone' => '+265 999 800 001', 'thread' => [['dir' => 'in', 'at' => date('Y-m-d H:i', time() - 600), 'text' => 'Hi', 'ch' => 'wa']], 'email' => 'q1@x.mw'];
    [$ok] = pm_wa_biz_send_approved($lead, 'Thanks for writing, we are happy to help you with that today.');
    pm_t_assert($ok && array_column($out, 1) === ['997'], 'an approved answer goes from the lead\'s business number even when another business is on screen: ' . json_encode($out));
    $out = [];
    $send = pm_wac_api_sender('', 'travel');
    $send('265999800002', 'Hello again', ['id' => 'q2', 'brand' => 'travel', 'thread' => [['dir' => 'in', 'ch' => 'wa', 'at' => date('Y-m-d H:i', time() - 600), 'text' => 'hi']]]);
    pm_t_eq(array_column($out, 1), ['998'], 'a campaign message goes from the campaign\'s own number');
    $out = [];
    $r = $send('265999800003', 'x', ['id' => 'q3', 'brand' => 'travel', 'thread' => []]);
    pm_t_assert(!$r[0] && str_contains($r[1], 'TM_WA_BIZ_TEMPLATE'), 'outside the 24-hour window with no template, the refusal names that business\'s own template key: ' . $r[1]);

    // templates belong to a number, so to a business
    putenv('TM_WA_BIZ_TEMPLATE=tm_hello');
    putenv('WA_BIZ_TEMPLATE=pm_hello');
    pm_t_eq(array_keys(pm_wa_templates('travel')), ['tm_hello'], 'Travel Malawi\'s default template is its own');
    pm_t_eq(array_keys(pm_wa_templates('promanaged')), ['pm_hello'], 'and ProManaged IT\'s is its own');
    pm_t_eq(array_keys(pm_wa_templates($id)), [], 'the added business has none until it names one');
    $_POST = ['tname' => 'sunrise_promo', 'tlang' => 'en'];
    pm_do_wa_template_add($id);
    pm_t_eq(array_keys(pm_wa_templates($id)), ['sunrise_promo'], 'a template named for one business is listed for it');
    pm_t_assert(!isset(pm_wa_templates('travel')['sunrise_promo']) && !isset(pm_wa_templates('promanaged')['sunrise_promo']), 'and for no other');
    $_POST = ['tname' => 'sunrise_promo'];
    pm_do_wa_template_remove('travel');
    pm_t_assert(isset(pm_wa_templates($id)['sunrise_promo']), 'removing a name from another business\'s list leaves it alone');
    pm_do_wa_template_remove($id);
    pm_t_eq(array_keys(pm_wa_templates($id)), [], 'and removing it from its own list works');
    $_POST = [];
    $GLOBALS['PM_WA_STUB'] = null;

    // X: its own account, never someone else's
    pm_t_assert(!pm_x_cfg('travel')['ready'], 'X is off for a business with no keys');
    putenv('X_API_KEY=k');
    putenv('X_API_SECRET=s');
    putenv('X_ACCESS_TOKEN=t');
    putenv('X_ACCESS_SECRET=ts');
    pm_t_assert(pm_x_cfg('promanaged')['ready'] && !pm_x_cfg('travel')['ready'] && !pm_x_cfg($id)['ready'], 'ProManaged IT\'s X keys do not turn X on for the others');
    $posted = [];
    $GLOBALS['PM_X_STUB'] = function ($text, $b = '') use (&$posted) {
        $posted[] = $b;
        return [true, 'id1'];
    };
    [$ok, $why] = pm_x_post('Hello', 'travel');
    pm_t_assert(!$ok && str_contains($why, 'TM_X_API_KEY') && $posted === [], 'a post for a business with no X account is refused, naming its keys: ' . $why);
    pm_x_post('Hello', 'promanaged');
    foreach (['X_API_KEY', 'X_API_SECRET', 'X_ACCESS_TOKEN', 'X_ACCESS_SECRET'] as $k) {
        putenv("TM_$k=tm" . $k);
    }
    pm_x_post('Hello', 'travel');
    pm_t_eq($posted, ['promanaged', 'travel'], 'each business posts through its own account');
    pm_t_eq(pm_job_channels_x($id), 'X not connected', 'the job for an added business stays off without its own keys');
    $GLOBALS['PM_X_STUB'] = null;

    // Setup health: a row each, naming each business's own keys
    $rows = array_column(pm_setup_health(), null, 'key');
    pm_t_assert(isset($rows["wa_$id"], $rows["x_$id"], $rows['wa_travel'], $rows['x_travel']) && !isset($rows['wa']) && !isset($rows['x']), 'Setup health has a WhatsApp and an X row for every business, and no shared one');
    pm_t_assert(str_contains($rows["x_$id"]['add'], $pre . 'X_API_KEY') && $rows['wa_travel']['add'] === '', 'a row that is connected stops asking for keys');
    pm_t_assert($rows['wa_promanaged']['ok'] && $rows["wa_$id"]['ok'] && $rows['x_travel']['ok'] && !$rows["x_$id"]['ok'], 'and shows each business\'s own state');

    // hiding a business takes its keys with it
    pm_t_eq(pm_brand_env('nosuchbusiness', 'WA_BIZ_TOKEN'), '', 'an unknown business has no keys (it never falls back to ProManaged IT\'s)');
    $clear();
    foreach (['X_API_KEY', 'X_API_SECRET', 'X_ACCESS_TOKEN', 'X_ACCESS_SECRET'] as $k) {
        putenv("TM_$k");
    }
});

t('Someone new who writes to a business\'s WhatsApp number becomes that business\'s lead', function () use (&$CREATED) {
    $id = $CREATED;
    $pre = strtoupper($id) . '_';
    foreach (['' => '999', 'TM_' => '998', $pre => '997'] as $p => $pid) {
        putenv($p . 'WA_BIZ_TOKEN=tok');
        putenv($p . 'WA_BIZ_PHONE_ID=' . $pid);
        putenv($p . 'WA_BIZ_VERIFY=v');
    }
    pm_save('leads', []);
    $ac = pm_load('agents_config', 'pm_agents_default_config');
    $ac['wa_reply_mode'] = 'auto';
    pm_save('agents_config', $ac);
    $o = pm_lp_clean(['title' => 'A free site visit', 'problem' => 'Power cuts', 'fix' => 'We look at your roof and tell you what would work.', 'keyword' => 'visit', 'button' => 'whatsapp', 'reply' => 'Thank you. Tell us about your building and a person will reply.'], $id);
    pm_lp_update(fn($rows) => [$o + ['id' => 'abcdef0123', 'status' => 'active', 'created' => date('Y-m-d H:i'), 'views' => 0]]);
    $sent = [];
    $GLOBALS['PM_WA_STUB'] = function ($to, $text, $pid = '') use (&$sent) {
        $sent[] = [$to, $pid, $text];
        return [true, 'wamid'];
    };
    $ai = 0;
    $GLOBALS['PM_AI_STUB'] = function ($sys, $user) use (&$ai) {
        $ai++;
        return json_encode(['intent' => 'question', 'summary' => 'asks about a room', 'needs_human' => false, 'reply_subject' => 'Re', 'reply_body' => 'Thanks for asking, we will reply.', 'whatsapp' => 'Thanks for asking, we will check and reply today.', 'resume_on' => '', 'next_step' => 'reply']);
    };
    $find = fn(string $brand, string $digits) => array_values(array_filter(pm_leads(), fn($l) => ($l['brand'] ?? '') === $brand && str_contains(preg_replace('/\D/', '', (string)$l['phone']), $digits)))[0] ?? null;

    // an offer's keyword: the owner's own reply, no AI, from that business's number
    pm_brand_set('promanaged');
    $note = pm_wa_biz_handle('265999800777', 'VISIT', '997', 'Chikondi Banda');
    $l = $find($id, '999800777');
    pm_t_assert(str_starts_with($note, 'new lead from WhatsApp:') && $l !== null, 'a new number writing the keyword becomes a lead of the business whose number it wrote to: ' . $note);
    pm_t_assert($l['name'] === 'Chikondi Banda' && $l['contact'] === 'Chikondi Banda' && $l['channel'] === 'whatsapp' && $l['source'] === 'whatsapp' && in_array($l['status'], ['replied', 'contacted'], true) && $l['src_tag'] === 'offer-abcdef0123',
        'named as they named themselves, warm, and tagged with the offer it came from');
    $in = array_values(array_filter($l['thread'], fn($m) => ($m['dir'] ?? '') === 'in' && ($m['ch'] ?? '') === 'wa'));
    pm_t_assert(count($in) === 1 && $in[0]['text'] === 'VISIT' && !empty(pm_wa_optin($l)), 'their message is in the thread once, as a WhatsApp message, which also records their agreement to be messaged on WhatsApp');
    pm_t_eq($ai, 0, 'a keyword needs no AI');
    pm_t_assert(count($sent) === 1 && $sent[0][1] === '997' && str_contains($sent[0][2], 'Tell us about your building'), 'the owner\'s own reply words went out, from that business\'s number: ' . json_encode($sent));
    pm_t_assert(!str_contains(json_encode($find($id, '999800777')['thread']), 'ProManaged'), 'and nothing of another business is in it');

    // anything else: a drafted answer that waits, never sent to a stranger by itself
    $sent = [];
    $note = pm_wa_biz_handle('265999800888', 'Do you have a room for Friday?', '998', 'Mary <b>Phiri</b>');
    $t = $find('travel', '999800888');
    pm_t_assert($t !== null && $t['name'] === 'Mary Phiri', 'a stranger\'s first message makes a lead for the business it came to, with a plain name: ' . ($t['name'] ?? '-'));
    pm_t_assert($sent === [] && $ai === 1 && !empty($t['reply_draft']['whatsapp']), 'its answer was drafted for the owner and not sent');
    pm_t_eq($find('promanaged', '999800888'), null, 'and no other business got a lead');

    // the same person writing to a second business is a lead of that one too, not a merge
    $sent = [];
    pm_wa_biz_handle('265999800888', 'Do you do websites?', '999', 'Mary');
    pm_t_assert($find('promanaged', '999800888') !== null && $find('travel', '999800888') !== null, 'the same person is a separate lead for each business they write to');
    // once known, the normal path
    $before = count(pm_leads());
    $note = pm_wa_biz_handle('265999800888', 'Thanks, one more question', '998');
    pm_t_assert(!str_starts_with($note, 'new lead') && count(pm_leads()) === $before, 'a second message from the same number is the ordinary conversation, not another lead');
    // STOP from nobody, and a message to a number nobody owns
    pm_t_eq(pm_wa_biz_handle('265999800999', 'STOP', '997'), 'STOP from a number with no lead: nothing to stop', 'STOP from a number we have no record of creates nothing');
    pm_t_eq($find($id, '999800999'), null, 'no lead was made for it');
    pm_update('leads', function (array $all) {
        foreach ($all as $k => $l) {
            if (str_contains(preg_replace('/\D/', '', (string)$l['phone']), '999800777')) {
                $all[$k]['status'] = 'optout';
            }
        }
        return $all;
    });
    $count = count(pm_leads());
    $sent = [];
    pm_t_assert(str_contains(pm_wa_biz_handle('265999800777', 'Please contact me again', '997', 'Chikondi'), 'do-not-contact number wrote again') && count(pm_leads()) === $count && $sent === [], 'a number that said STOP is never a new lead and never answered');
    pm_t_eq(pm_wa_biz_handle('265999801000', 'hello', '555'), 'a number that is not connected to any business: ignored', 'a message to a number no business owns is ignored');
    // the AI being down does not lose the message
    $GLOBALS['PM_AI_STUB'] = function () { throw new RuntimeException('AI is down'); };
    pm_wa_biz_handle('265999801111', 'Is anyone there?', '999', 'Joyce');
    $j = $find('promanaged', '999801111');
    pm_t_assert($j !== null && str_contains(json_encode($j['thread']), 'Is anyone there?'), 'if the AI is down the message is still kept, for a person to read');
    $GLOBALS['PM_AI_STUB'] = null;
    $GLOBALS['PM_WA_STUB'] = null;
    pm_lp_update(fn($rows) => []);
    pm_save('leads', []);
    foreach (['', 'TM_', $pre] as $p) {
        foreach (['WA_BIZ_TOKEN', 'WA_BIZ_PHONE_ID', 'WA_BIZ_VERIFY'] as $k) {
            putenv($p . $k);
        }
    }
    pm_brand_set('promanaged');
});

/* =========================================================== social */

t('Social: its own themes, tags, ideas and invitation', function () use (&$CREATED, &$SEEN, $stub) {
    $id = $CREATED;
    pm_brand_set($id);
    pm_t_eq(array_column(pm_default_pillars($id), 'name'), ['Tip/How-to', 'Proof', 'Behind the scenes', 'Offer', 'Question', 'Free first step'], 'the starter set of content themes, with one for the free first step it offers');
    $tags = pm_default_bank_tags($id);
    pm_t_assert(in_array('#Lilongwe', $tags['local'], true) && in_array('#SunriseSolar', $tags['niche'], true) && !preg_match(LEAK, json_encode($tags)), 'hashtags come from its own name and cities: ' . json_encode($tags));
    pm_t_eq(pm_default_ideas($id), [], 'no starter ideas written for another business');
    pm_t_eq(pm_magnet_keyword_line($id), 'Send QUOTE on WhatsApp for a free site visit.', 'the invitation uses the default keyword and the free first step the owner named');
    $seeds = pm_social_bank($id);
    $kinds = array_count_values(array_column($seeds, 'kind'));
    pm_t_assert(($kinds['fact'] ?? 0) >= 2, 'its facts are ready to write about');
    pm_t_assert(!preg_match(LEAK, json_encode($seeds)), 'no seed mentions another business');
    pm_t_assert(!isset($kinds['pain']) && !isset($kinds['module']) && !isset($kinds['benefit']), 'ProManaged IT\'s pain points and modules are not used');
    pm_t_assert(!isset($kinds['season']) && !isset($kinds['stay']) && !isset($kinds['place']), 'Travel Malawi\'s seasons and stays are not used');
    pm_t_eq($kinds['magnet'] ?? 0, 3, 'three invitation angles because the owner named a free first step');
    // no free first step: nothing is promised that was not set up
    $raw = pm_load('settings', 'pm_default_settings');
    $raw['brands'][$id]['profile']['magnet'] = '';
    pm_save('settings', $raw);
    pm_t_eq(pm_magnet_keyword_line($id), '', 'without a free first step there is no invitation line');
    pm_t_assert(!in_array('magnet', array_column(pm_social_bank($id, date('Y-m-d', strtotime('+3 days'))), 'kind'), true), 'and no invitation seeds');
    // a keyword of its own
    $raw['brands'][$id]['profile']['magnet'] = 'a free site visit';
    $raw['brands'][$id]['profile']['cta_keyword'] = 'SOLAR';
    pm_save('settings', $raw);
    pm_t_eq(pm_magnet_keyword_line($id), 'Send SOLAR on WhatsApp for a free site visit.', 'the invitation names the keyword and the free first step');
    $seeds = pm_social_bank($id, date('Y-m-d', strtotime('+1 day')));
    $mag = array_values(array_filter($seeds, fn($s) => $s['kind'] === 'magnet'));
    pm_t_assert(count($mag) === 3 && str_contains($mag[0]['topic'], 'free site visit') && !preg_match(LEAK, json_encode($mag)), 'three invitation angles about the free site visit');
    // the offline plan and the AI plan
    $rows = pm_plan_offline($id, 4, date('Y-m-d', strtotime('+1 day')));
    pm_t_assert(count($rows) >= 3, 'a plan is made without the AI (' . count($rows) . ' posts)');
    pm_t_assert(!preg_match(LEAK, json_encode(array_map(fn($r) => [$r['caption'], $r['headline'], $r['sub']], $rows))), 'no post says anything about another business');
    pm_t_assert(count(array_filter($rows, fn($r) => ($r['brand'] ?? '') === $id)) === count($rows), 'every post belongs to the business');
    $GLOBALS['PM_AI_STUB'] = $stub;
    $SEEN = [];
    pm_sx_build($id, 3, date('Y-m-d', strtotime('+2 days')), 'mix', true);
    pm_t_assert($SEEN && !preg_match(LEAK, implode(' ', $SEEN)) && str_contains($SEEN[0], 'Sunrise Solar'), 'the planner prompt is about it alone');
    [$sys] = pm_trend_prompt($id);
    pm_t_assert(str_contains($sys, 'Sunrise Solar') && !preg_match(LEAK, $sys), 'the trend radar asks about its customers');
    pm_t_eq(pm_trend_line($id), '', 'no trend line until the weekly job has run');
    pm_t_eq(pm_sx_segment(['caption' => 'Solar for schools and clinics', 'brand' => $id], $id), 'Schools', 'a post is tagged with the target type it speaks to');
    pm_t_eq(pm_sx_segment(['caption' => 'Good morning', 'brand' => $id], $id), 'All customers', 'or all customers');
    pm_brand_set('promanaged');
});

t('Social settings and the partnership radar are per business', function () use (&$CREATED, &$SEEN, $stub) {
    $id = $CREATED;
    $s = pm_social_settings($id);
    pm_t_assert($s['channels']['facebook'] === true && $s['channels']['linkedin'] === false, 'default channels');
    $s['cover_head'] = 'Power you can count on';
    pm_social_settings_save($id, $s);
    pm_t_eq(pm_social_settings($id)['cover_head'], 'Power you can count on', 'its page settings save under its own block');
    pm_t_assert(pm_social_settings('promanaged')['cover_head'] !== 'Power you can count on', 'ProManaged IT\'s are untouched');
    putenv('PM_TEST_NOW=2026-10-12 09:00'); // a Monday
    $GLOBALS['PM_AI_STUB'] = $stub;
    $SEEN = [];
    pm_jobg_partners();
    putenv('PM_TEST_NOW');
    $mine = array_values(array_filter($SEEN, fn($p) => str_contains($p, 'Partnership radar for Sunrise Solar')));
    pm_t_assert(count($mine) === 1 && !preg_match(LEAK, $mine[0]), 'the radar asks about pages its own customers follow: ' . substr($mine[0] ?? '', 0, 140));
    pm_t_assert(count($SEEN) === 3, 'one search call per business (' . count($SEEN) . ')');
});

t('Setup health and the checklist cover every business', function () use (&$CREATED) {
    $id = $CREATED;
    $rows = pm_setup_health();
    $keys = array_column($rows, 'key');
    pm_t_assert(in_array("smtp_$id", $keys, true) && in_array("imap_$id", $keys, true) && in_array("fb_$id", $keys, true), 'the panel has the business\'s mail, reply and Facebook rows');
    $smtp = array_values(array_filter($rows, fn($r) => $r['key'] === "smtp_$id"))[0];
    pm_t_assert($smtp['ok'] === true && str_contains($smtp['label'], 'Sunrise Solar'), 'mail is ready once its mailbox is typed in');
    $cl = pm_brand_checklist($id);
    pm_t_eq(array_column($cl, 'ok'), [true, true, true, true], 'the checklist is all ready after onboarding with these answers');
    pm_t_eq(pm_brand(), 'promanaged', 'checking does not move the app to another business');
});

t('The scheduler starts a business that was added in the app', function () use (&$CREATED) {
    $id = $CREATED;
    $started = [];
    $GLOBALS['PM_BRAND_RUN_STUB'] = function ($b) use (&$started) { $started[] = $b; };
    $GLOBALS['PM_AI_STUB'] = fn() => '[]';
    putenv('GEMINI_API_KEY=test-key');
    putenv('PM_TEST_NOW=2026-10-12 09:00'); // Monday morning
    pm_brand_set($id);
    pm_t_eq(pm_job_brand_agent_run($id), 'daily agent run started', 'a weekday morning starts it');
    pm_run_state(['state' => 'idle', 'started' => '2026-10-12 08:00:00', 'beat' => time()]);
    pm_t_eq(pm_job_brand_agent_run($id), '', 'but only once a day');
    pm_run_state(['state' => 'idle']);
    putenv('PM_TEST_NOW=2026-10-11 09:00'); // Sunday
    pm_t_eq(pm_job_brand_agent_run($id), '', 'not at the weekend');
    putenv('PM_TEST_NOW=2026-10-12 06:00');
    pm_t_eq(pm_job_brand_agent_run($id), '', 'not before 07:30');
    putenv('PM_TEST_NOW=2026-10-12 09:00');
    pm_brand_set('promanaged');
    pm_t_eq(pm_job_brand_agent_run('promanaged') . pm_job_brand_agent_run('travel'), '', 'the two original businesses keep their own scheduled task');
    $raw = pm_load('brands', fn() => []);
    $raw[$id]['daily_run'] = false;
    pm_save('brands', $raw);
    pm_t_eq(pm_job_brand_agent_run($id), '', 'and it can be switched off');
    pm_t_eq($started, [$id], 'exactly one start was recorded');
    putenv('PM_TEST_NOW');
    putenv('GEMINI_API_KEY');
    $GLOBALS['PM_BRAND_RUN_STUB'] = null;
    pm_run_state(['state' => 'idle']);
});

t('Other kinds of business, names and numbers of businesses', function () use ($ANS, $stub, &$SEEN) {
    $draftOf = fn(array $a) => pm_brand_draft($a)['draft'];
    // a business that sells to the public: the agents do not go cold-calling for customers
    $cafe = ['name' => 'Café Zomba!', 'sells' => 'A café and bakery on the Zomba road serving breakfast, lunch and fresh bread every day.', 'customers' => 'Families, students and travellers passing through Zomba',
        'sell_to' => 'public', 'cities' => 'Zomba', 'magnet' => '', 'voice' => 'warm'];
    [$cid, $err] = pm_brand_create($cafe, $draftOf($cafe));
    pm_t_assert($err === [] && preg_match('/^[a-z][a-z0-9]{1,15}$/', $cid), 'a name with an accent and punctuation becomes a safe id: ' . $cid);
    $cc = pm_agents_config($cid);
    pm_t_assert($cc['enabled']['scout'] === false && $cc['enabled']['writer'] === true && $cc['enabled']['proposals'] === false, 'selling to the public switches the scouts off and keeps replies, follow-ups and the writer');
    pm_t_eq(pm_brand_profile($cid)['sell_to'], 'public', 'and it is remembered');
    pm_t_eq(pm_default_pillars($cid)[0]['name'], 'Tip/How-to', 'it still gets a starter set of content themes');
    pm_t_eq(pm_magnet_keyword_line($cid), '', 'with no free first step it invites nobody to anything');
    // a name that starts with a digit, a very long one, a duplicate and a reserved word
    [$d1] = pm_brand_create(['name' => '2020 Hardware Supplies Limited Malawi'] + $ANS, $draftOf($ANS));
    pm_t_assert(preg_match('/^[a-z][a-z0-9]{1,15}$/', $d1) && strlen($d1) <= 13, 'a name starting with a digit still gets a letter-first id of sensible length: ' . $d1);
    [$d2] = pm_brand_create(['name' => '2020 Hardware Supplies Limited Malawi'] + $ANS, $draftOf($ANS));
    pm_t_assert($d2 !== $d1 && pm_brand_valid($d2), 'the same long name twice gets two different ids');
    pm_t_assert(pm_brand_check_answers(['name' => 'travel'] + $ANS) !== [], 'the word "travel" on its own is refused');
    foreach (['default', 'all', 'settings'] as $res) {
        [$rid] = pm_brand_create(['name' => $res] + $ANS, $draftOf($ANS));
        pm_t_assert($rid !== $res && !in_array($rid, PM_BRAND_RESERVED, true), "\"$res\" as a name never becomes the reserved id");
    }
    // many businesses side by side, each only seeing its own leads
    $ids = array_values(array_filter(pm_brand_ids(), fn($i) => pm_brand_is_custom($i)));
    pm_t_assert(count($ids) >= 6, 'six or more added businesses coexist (' . count($ids) . ')');
    $leads = [];
    foreach ($ids as $i => $bid) {
        $leads["x$i"] = ['id' => "x$i", 'brand' => $bid, 'name' => "Lead of $bid", 'type' => 'shop', 'city' => 'Zomba', 'status' => 'drafted', 'score' => 70, 'email' => "a$i@lead$i.mw", 'drafts' => ['email_body' => 'Hello'], 'sent' => [], 'notes' => [], 'thread' => []];
    }
    pm_save('leads', $leads);
    $own = [];
    foreach ($ids as $i => $bid) {
        pm_brand_set($bid);
        $titles = implode('|', array_column(pm_daily_plan(pm_leads(), pm_agents_config()), 'title'));
        $own[$bid] = substr_count($titles, 'Lead of ') === 1 && str_contains($titles, "Lead of $bid");
    }
    pm_brand_set('promanaged');
    pm_t_eq(array_values(array_unique($own)), [true], 'each business\'s Today list holds exactly its own lead');
    // prompts of the many stay free of each other's names
    $GLOBALS['PM_AI_STUB'] = $stub;
    $SEEN = [];
    foreach ($ids as $bid) {
        pm_brand_set($bid);
        pm_agent_write([['id' => 'x1', 'name' => 'Lead', 'type' => 'shop', 'city' => 'Zomba', 'contact' => '', 'evidence' => [], 'social_gaps' => [], 'pain' => '']]);
    }
    pm_brand_set('promanaged');
    $names = array_map('pm_brand_name', $ids);
    $cross = 0;
    foreach ($SEEN as $k => $p) {
        foreach ($names as $n) {
            if (!str_contains($p, 'writing outreach for ' . $n) && str_contains($p, $n . ':')) {
                $cross++;
            }
        }
    }
    pm_t_eq($cross, 0, 'no business\'s writer is ever told another business\'s facts');
    pm_save('leads', []);
});

t('The two original businesses still behave exactly as before', function () {
    pm_brand_set('travel');
    pm_t_assert(str_contains(pm_agents_company_brief('tiny'), 'Travel Malawi') && str_contains(pm_agents_company_brief('tiny'), 'Stay Onboarding'), 'Travel Malawi\'s brief is unchanged');
    pm_brand_set('promanaged');
    pm_t_assert(str_contains(pm_agents_company_brief('full'), 'Hotel Starter') && str_contains(pm_agents_company_brief('short'), 'Build:'), 'ProManaged IT\'s brief is unchanged');
    pm_t_eq(pm_lead_id('Blend Lodge', 'Zomba', 'travel'), substr(md5('travel|' . strtolower(preg_replace('/\b(hotel|lodge|ltd|limited|the|restaurant|bar|gym|&|and)\b|[^a-z0-9]+/i', '', 'Blend Lodge')) . '|zomba'), 0, 12), 'lead ids are the same as before, so no existing lead is lost');
    pm_t_eq([pm_run_state_file('promanaged'), pm_run_state_file('travel'), pm_run_state_file('acme')], ['agent_run.json', 'agent_run_travel.json', 'agent_run_acme.json'], 'run-state files keep their names');
    pm_t_assert(pm_default_pillars('travel')[0]['name'] === 'Destination/inspiration' && pm_default_pillars('promanaged')[0]['name'] === 'Tip/How-to', 'content themes unchanged');
    $r = pm_reply_template('travel', 'how much for a room?', '');
    pm_t_assert($r !== null && str_contains($r['text'], 'stay'), 'Travel Malawi\'s reply wording is unchanged');
});

pm_t_done();
