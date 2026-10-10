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
    pm_t_assert(count($SEEN) >= 8, 'eight agent calls were captured (' . count($SEEN) . ')');
    $bad = [];
    foreach ($SEEN as $p) {
        if (preg_match(LEAK, $p, $m)) {
            $bad[] = $m[0] . ' in: ' . substr($p, 0, 90);
        }
    }
    pm_t_eq($bad, [], 'no prompt mentions ProManaged IT, Travel Malawi, their packages or their products');
    pm_t_assert(count(array_filter($SEEN, fn($p) => str_contains($p, 'Sunrise Solar'))) === count($SEEN), 'every prompt names the business it works for');
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

/* =========================================================== social */

t('Social: its own themes, tags, ideas and invitation', function () use (&$CREATED, &$SEEN, $stub) {
    $id = $CREATED;
    pm_brand_set($id);
    pm_t_eq(array_column(pm_default_pillars($id), 'name'), ['Tip/How-to', 'Proof', 'Behind the scenes', 'Offer', 'Question'], 'the starter set of content themes');
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
