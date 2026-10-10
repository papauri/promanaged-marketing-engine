<?php
/**
 * Tests for the Travel Malawi first email: a personal opening (the only part the AI writes) plus the standard part from the owner's own wording (benefits, set-up steps,
 * the link, free and why), held to the same wording rules as any first email; the rewrite of unsent drafts that leaves approved, edited and sent ones alone.
 * Runs on a TEMP COPY of data/ with the AI stubbed. Run: php tests/test_tmmail.php
 */
require __DIR__ . '/boot.php';
$T = pm_test_boot();
pm_brand_set('travel');
$GLOBALS['PM_AI_STUB'] = null;
unset($GLOBALS['PM_WHO']);

function t(string $name, callable $fn): void
{
    echo "\n$name\n";
    try {
        $fn();
    } catch (Throwable $e) {
        pm_t_assert(false, "$name threw " . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

$setHostEmail = function (?array $he) {
    $raw = pm_load('settings', 'pm_default_settings');
    $raw['travel']['host_email'] = $he;
    if ($he === null) {
        unset($raw['travel']['host_email']);
    }
    pm_save('settings', $raw);
};
$setHostEmail(null);
$raw0 = pm_load('settings', 'pm_default_settings');
$raw0['travel']['signatory_name'] = ''; // no named sender: the opening names the company
pm_save('settings', $raw0);
$lead = ['id' => 'a1', 'name' => 'Mufasa Eco Lodge', 'city' => 'Zomba', 'contact' => 'Grace Phiri', 'type' => 'lodge'];

t('The email is a greeting, an opening, and the standard part, and passes the wording rules', function () use ($lead) {
    $body = pm_tm_email_compose($lead);
    pm_t_assert(str_starts_with($body, "Hello Grace,\n\nI am writing from Travel Malawi, and I came across Mufasa Eco Lodge while looking at places to stay in Zomba.\n\n"), 'it greets the contact by first name and opens with something true: ' . substr($body, 0, 200));
    foreach (['Travel Malawi is a direct booking platform', 'What you get:', '- Guests book and pay you directly, in kwacha or dollars.', '- You control your own rooms, rates and blocked dates.', 'How to start:',
        '1. Go to https://ulendomalawi.com and send', '2. We review your listing before it goes live.', '3. Add your rooms, rates and blocked dates', 'There is no commission, no listing fee and no monthly charge for now.',
        'We are building the platform together with our first hosts, so we are not charging while we do.', 'Shall I send you the host starter guide?'] as $needle) {
        pm_t_assert(str_contains($body, $needle), "it says: $needle");
    }
    pm_t_eq(preg_match_all('#https?://#', $body), 1, 'with exactly one link, to ulendomalawi.com');
    $words = str_word_count($body);
    pm_t_assert($words >= 20 && $words <= 190 && pm_outreach_lint('Direct bookings for Mufasa Eco Lodge', $body, true) === [], "it is $words words and passes the first-email lint: " . json_encode(pm_outreach_lint('Direct bookings for Mufasa Eco Lodge', $body, true)));
    pm_t_assert(!preg_match('/MWK|\$|guarantee|no obligation|limited time/i', $body), 'no price, no spam wording, no guarantee');
    pm_t_assert(str_starts_with(pm_tm_email_compose(['name' => 'X Lodge', 'city' => 'Malawi', 'contact' => '']), "Hello,\n\nI am writing from Travel Malawi, and I came across X Lodge while looking at places to stay in Malawi."), 'with no contact name it greets plainly, and a country-wide "city" reads naturally');
    pm_t_assert(str_starts_with(pm_tm_email_compose(['name' => 'Y', 'contact' => 'Dr <b>Mary</b> Banda']), 'Hello Dr,'), 'a contact name with markup is reduced to letters');
    $GLOBALS['PM_WHO'] = 'Chikondi Mwale';
    pm_t_assert(str_contains(pm_tm_email_compose($lead), "I'm Chikondi from Travel Malawi, and I came across Mufasa Eco Lodge"), 'when a person is working as, the opening says who is writing');
    unset($GLOBALS['PM_WHO']);
});

t('The owner\'s own wording is what is sent; an empty field says nothing', function () use ($lead, $setHostEmail) {
    $setHostEmail(['intro' => 'We list independent stays.', 'benefits_title' => 'Why list:', 'benefits' => ['Direct guests.'], 'steps_title' => 'Steps:', 'steps' => ['Open {link} today.', 'Wait for our review.'],
        'link' => 'https://example.mw/host', 'free' => 'No fee.', 'why' => '', 'closing' => 'Interested?']);
    $b = pm_tm_email_compose($lead);
    pm_t_assert(str_contains($b, "We list independent stays.\n\nWhy list:\n- Direct guests.\n\nSteps:\n1. Open https://example.mw/host today.\n2. Wait for our review.\n\nNo fee.\n\nInterested?"), 'what was saved is what is used, with {link} filled in: ' . $b);
    pm_t_assert(!str_contains($b, 'first hosts') && !str_contains($b, 'host starter guide'), 'and nothing of the starting text stays behind');
    $setHostEmail(['link' => '', 'steps' => ["Go to {link}."]]);
    $b = pm_tm_email_compose($lead);
    pm_t_assert(str_contains($b, '1. Go to https://ulendomalawi.com.') && str_contains($b, 'What you get:') && str_contains($b, 'Shall I send you the host starter guide?'), 'a field never saved keeps the starting text, and an empty link falls back to ulendomalawi.com');
    $setHostEmail(['steps' => ['One link https://a.example and another https://b.example.'], 'link' => 'https://ulendomalawi.com']);
    $c = pm_tm_email_check();
    pm_t_assert(str_contains(implode(' ', $c['problems']), 'Too many links'), 'a second link is caught, and Settings shows it: ' . implode(' ', $c['problems']));
    $setHostEmail(['benefits' => array_fill(0, 6, str_repeat('word ', 20)), 'steps' => array_fill(0, 6, str_repeat('word ', 20))]);
    $c = pm_tm_email_check();
    pm_t_assert($c['words'] > 190 && str_contains(implode(' ', $c['problems']), 'between 20 and 190'), 'an email that grows past 190 words is caught too (' . $c['words'] . ')');
    $setHostEmail(null);
});

t('The AI\'s opening is made safe', function () {
    pm_t_eq(pm_tm_clean_opening("Hello Mary,\n I'm Chikondi from Travel Malawi. I saw your <b>lakeshore</b> lodge on Facebook!"), "I'm Chikondi from Travel Malawi. I saw your lakeshore lodge on Facebook.", 'a greeting is removed (the app adds its own), tags go, an exclamation mark becomes a full stop');
    pm_t_eq(pm_tm_clean_opening('One. Two. Three. Four.'), 'One. Two.', 'at most two sentences');
    foreach (['See https://x.example now', 'Visit www.x.example', 'You have 12 rooms', 'It costs MWK 5000', 'It is $20', '', '   ', str_repeat('word ', 41) . 'end.'] as $bad) {
        pm_t_eq(pm_tm_clean_opening($bad), '', 'unusable: ' . substr($bad, 0, 24));
    }
});

t('The Writer for Travel Malawi: AI opening, subject and WhatsApp; the rest is the standard text', function () use ($lead) {
    $seen = '';
    $GLOBALS['PM_AI_STUB'] = function ($sys, $user) use (&$seen) {
        $seen = $sys . "\n" . $user;
        return json_encode([['id' => 'a1', 'email_subject' => 'Direct bookings for Mufasa', 'opening' => "Hello Grace, I'm Chikondi from Travel Malawi. I came across Mufasa while looking at places to stay near Zomba plateau.", 'whatsapp' => 'Hello Grace, this is Chikondi from Travel Malawi.'],
            ['id' => 'b2', 'email_subject' => str_repeat('Too long ', 12), 'opening' => 'Visit https://spam.example now!', 'whatsapp' => ''],
            ['id' => 'zz', 'email_subject' => 'Not asked for', 'opening' => 'x']]);
    };
    $b = ['id' => 'b2', 'name' => 'Lakeview Cottage', 'city' => 'Salima', 'contact' => ''];
    $c = ['id' => 'c3', 'name' => 'Third Camp', 'city' => 'Mzuzu', 'contact' => 'Peter'];
    $r = pm_agent_write([$lead, $b, $c]);
    pm_t_eq(array_column($r, 'id'), ['a1', 'b2', 'c3'], 'every lead gets a draft, in order, even one the AI left out; one it was not asked for is ignored');
    pm_t_assert(str_contains($r[0]['email_body'], "I'm Chikondi from Travel Malawi. I came across Mufasa while looking at places to stay near Zomba plateau.\n\nTravel Malawi is a direct booking platform") && !str_contains($r[0]['email_body'], "Hello Grace, I'm"), 'the AI\'s opening is used (its own greeting removed), followed by the standard part');
    pm_t_eq($r[0]['email_subject'], 'Direct bookings for Mufasa', 'and its subject');
    pm_t_assert(str_contains($r[1]['email_body'], 'I am writing from Travel Malawi, and I came across Lakeview Cottage while looking at places to stay in Salima.') && $r[1]['email_subject'] === 'Direct bookings for Lakeview Cottage', 'an opening with a link, or a subject that is too long, falls back to plain true ones');
    pm_t_assert(str_starts_with($r[1]['whatsapp'], 'Hello, this is the team at Travel Malawi.') && str_contains($r[2]['whatsapp'], 'Travel Malawi'), 'a missing WhatsApp message gets a short true one');
    pm_t_assert(str_contains($seen, 'is a fixed text the app adds, so never describe it') && !str_contains($seen, 'How to start') && !str_contains($seen, 'Shall I send you') && !str_contains($seen, 'ulendomalawi.com'), 'the AI is told the rest is fixed text, and is not given it to rewrite');
    foreach ($r as $x) {
        pm_t_eq(pm_outreach_lint($x['email_subject'], $x['email_body'], true), [], 'it passes the lint: ' . $x['email_subject']);
    }
    // the other businesses write as before
    pm_brand_set('promanaged');
    $GLOBALS['PM_AI_STUB'] = fn($sys) => json_encode([['id' => 'a1', 'email_subject' => 'Hello there', 'email_body' => 'AI wrote this whole email itself.', 'whatsapp' => 'wa']]);
    $p = pm_agent_write([$lead]);
    pm_t_eq($p[0]['email_body'], 'AI wrote this whole email itself.', 'ProManaged IT still gets the email the AI writes');
    pm_brand_set('travel');
    $GLOBALS['PM_AI_STUB'] = null;
});

t('Redrafting unsent emails in the new format leaves the rest alone', function () use ($setHostEmail) {
    $old = "Hello,\nWe would love to list you on Travel Malawi, free.";
    // names carry no digits: an opening with a number in it is not used
    $mk = fn($id, $x = []) => $x + ['id' => $id, 'brand' => 'travel', 'name' => 'Stay ' . strtr($id, '0123456789', 'abcdefghij'), 'city' => 'Zomba', 'type' => 'lodge', 'status' => 'drafted', 'contact' => '', 'sent' => [], 'drafts' => ['email_subject' => 'Old subject', 'email_body' => $old, 'whatsapp' => 'old wa'], 'notes' => []];
    pm_save('leads', [
        't1' => $mk('t1'), 't2' => $mk('t2', ['approved_at' => '2026-10-10 09:00']), 't3' => $mk('t3', ['drafts' => ['email_subject' => 'S', 'email_body' => 'Edited by hand', 'whatsapp' => '', 'edited_at' => '2026-10-10 10:00']]),
        't4' => $mk('t4', ['sent' => ['2026-10-09 10:00']]), 't5' => $mk('t5', ['drafts' => ['email_subject' => 'S', 'email_body' => 'Already new: https://ulendomalawi.com', 'whatsapp' => '']]),
        't6' => $mk('t6', ['status' => 'contacted']), 't7' => $mk('t7', ['drafts' => []]), 'p1' => $mk('p1', ['brand' => 'promanaged']), 't8' => $mk('t8'),
    ]);
    pm_t_eq(pm_tm_rewrite_candidates(pm_leads()), ['t1', 't8'], 'only unsent, unapproved, unedited Travel Malawi drafts in the old format are candidates (not approved, hand-edited, sent, already new, contacted, empty, or another business)');
    pm_t_eq(pm_tm_rewrite_kept(pm_leads()), ['approved' => 1, 'edited' => 1], 'and the screen can say how many were kept and why');
    $GLOBALS['PM_AI_STUB'] = fn($sys, $user) => json_encode(array_map(fn($x) => ['id' => $x['id'], 'email_subject' => 'New subject ' . $x['id'], 'opening' => "I'm Ada from Travel Malawi. I saw " . $x['name'] . ' online.', 'whatsapp' => 'new wa'], json_decode(substr($user, 0, strpos($user, "\nJSON array")), true)));
    $r = pm_tm_rewrite_drafts();
    pm_t_eq($r, ['rewritten' => 2, 'failed' => 0], 'two were redrafted');
    $l = pm_leads();
    pm_t_assert(str_contains($l['t1']['drafts']['email_body'], 'I saw Stay tb online.') && str_contains($l['t1']['drafts']['email_body'], 'https://ulendomalawi.com') && $l['t1']['drafts']['email_subject'] === 'New subject t1' && str_contains(json_encode($l['t1']['notes']), 'new Travel Malawi format'),
        'with its own opening, the standard part, a new subject, and a note on the lead');
    pm_t_eq([$l['t2']['drafts']['email_body'], $l['t3']['drafts']['email_body'], $l['t4']['drafts']['email_body'], $l['p1']['drafts']['email_body']], [$old, 'Edited by hand', $old, $old], 'approved, hand-edited, sent and other-business drafts are exactly as they were');
    pm_t_eq(pm_tm_rewrite_candidates(pm_leads()), [], 'and nothing is left to do');
    // an AI that fails changes nothing
    pm_save('leads', ['t1' => $mk('t1'), 't2' => $mk('t2')]);
    $GLOBALS['PM_AI_STUB'] = function () { throw new RuntimeException('quota exceeded'); };
    pm_t_eq(pm_tm_rewrite_drafts(), ['rewritten' => 0, 'failed' => 2], 'an AI that fails is reported, not hidden');
    pm_t_eq(pm_leads()['t1']['drafts']['email_body'], $old, 'and the drafts are untouched');
    $GLOBALS['PM_AI_STUB'] = null;
});

pm_t_done();
