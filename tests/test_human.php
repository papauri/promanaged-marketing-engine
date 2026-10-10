<?php
/**
 * Tests for the human voice layer: the tells that make marketing text read as machine-written are fixed in code (em dashes, stock openers, buzzwords, stacked exclamation marks, emoji in
 * an email), numbers, links and meaning are never touched, polishing twice changes nothing, what is left is reported, and every writer's output goes through it.
 * Run: php tests/test_human.php
 */
require __DIR__ . '/boot.php';
$T = pm_test_boot();
require_once dirname(__DIR__) . '/lib/engage.php';
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

t('Dashes', function () {
    pm_t_eq(pm_human_polish("We fix computers \u{2014} fast and cheaply.", 'email'), 'We fix computers, fast and cheaply.', 'a dash that breaks a sentence becomes a comma');
    pm_t_eq(pm_human_polish("We fix computers.\u{2014}Call us.", 'email'), 'We fix computers. Call us.', 'before a capital it becomes a full stop');
    pm_t_eq(pm_human_polish("Open Mon\u{2013}Fri 08:00\u{2013}17:00, call 0999\u{2013}123\u{2013}456", 'email'), 'Open Mon-Fri 08:00-17:00, call 0999-123-456', 'ranges keep a plain hyphen and numbers are untouched');
    pm_t_eq(pm_human_polish('A long -- very long -- wait', 'whatsapp'), 'A long, very long, wait', 'a double hyphen is treated the same way');
    pm_t_eq(pm_human_polish('A well-known, cost-effective plan: pay-as-you-go', 'email'), 'A well-known, cost-effective plan: pay-as-you-go', 'ordinary hyphens are left alone');
});

t('Stock phrases and buzzwords', function () {
    pm_t_eq(pm_human_polish("Hello Grace,\n\nI hope this email finds you well. We build websites.", 'email'), "Hello Grace,\n\nWe build websites.", '"I hope this email finds you well" is cut');
    pm_t_eq(pm_human_polish("In today's fast-paced world, shops need simple records.", 'email'), 'Shops need simple records.', 'and "in today\'s fast-paced world", with the next word capitalised');
    pm_t_eq(pm_human_polish('We leverage a robust, seamless system to streamline stock and unlock growth.', 'email'), 'We use a solid, easy system to simplify stock and open up growth.', 'buzzwords become plain words');
    pm_t_eq(pm_human_polish('Seamless billing. Leveraging local data.', 'email'), 'Easy billing. Using local data.', 'a capital at the start of a sentence is kept');
    pm_t_eq(pm_human_polish("We're thrilled to announce our new shop.", 'email'), 'We are glad to tell you about our new shop.', 'a stock announcement becomes ordinary speech');
    pm_t_eq(pm_human_polish('Our cutting-edge, game-changer tool will delve into your sales.', 'email'), 'Our modern, big help tool will look at your sales.', 'phrases are swapped whole');
    pm_t_eq(pm_human_polish('The leveraged loan and the seamstress were robustly paid.', 'email'), 'The used loan and the seamstress were robustly paid.', 'only whole words change (seamstress and robustly are not buzzwords)');
});

t('Exclamation marks and emoji', function () {
    pm_t_eq(pm_human_polish('Hello! We can help! Call us!', 'email'), 'Hello. We can help. Call us.', 'an email has none');
    pm_t_eq(pm_human_polish('Hello! We can help! Call us!', 'whatsapp'), 'Hello! We can help. Call us.', 'a WhatsApp message keeps the first');
    pm_t_eq(pm_human_polish('Hi Grace 👋 quick one 🙏', 'email'), 'Hi Grace quick one', 'emoji do not belong in an email');
    pm_t_eq(pm_human_polish('Hi Grace 👋', 'whatsapp'), 'Hi Grace 👋', 'but may stay in a WhatsApp message');
    pm_t_eq(pm_human_polish('Great tip!! Try it today!', 'post'), 'Great tip!! Try it today!', 'a post is left as the writer made it');
});

t('Nothing important is touched', function () {
    $s = "Hello Peter,\n\nOur office is at https://www.example.mw/contact (09:00-17:00). Call +265 999 123 456 or write to info@example.mw. K45,000 is the fee, no more than 12 rooms.\n\nRegards";
    pm_t_eq(pm_human_polish($s, 'email'), $s, 'links, times, phone numbers, emails, amounts and paragraphs stay exactly as written');
    pm_t_eq(pm_human_polish('', 'email'), '', 'empty stays empty');
    $messy = "i hope this message finds you well.  we use   a robust tool \u{2014} it's great!";
    $once = pm_human_polish($messy, 'email');
    pm_t_eq(pm_human_polish($once, 'email'), $once, 'polishing twice changes nothing: ' . $once);
    pm_t_assert(str_starts_with($once, 'We use a solid tool'), 'and the text starts with a capital after a cut: ' . $once);
});

t('What is left is reported', function () {
    pm_t_eq(pm_human_flags('A plain note about your shop. Call me on Friday.'), [], 'a natural text has no flags');
    $f = pm_human_flags("We will delve into this \u{2014} it is not just a tool, it's a revolution! Seamless.");
    pm_t_assert(in_array('em dash', $f, true) && in_array('delve into', $f, true) && in_array('seamless', $f, true) && in_array('not just X, but Y', $f, true) && in_array('too many exclamation marks', $f, true), 'the tells are named: ' . implode(' | ', $f));
    pm_t_eq(pm_human_flags(pm_human_polish("We will delve into this \u{2014} seamless! Yes!", 'email')), [], 'and nothing is left after the polish');
    pm_t_assert(in_array('emoji in an email', pm_human_flags('Hello 😀', 'email'), true) && pm_human_flags('Hello 😀', 'whatsapp') === [], 'emoji are only a flag in an email');
    pm_t_assert(in_array('In today\'s fast-paced world,', pm_human_flags("In today's fast-paced world, you need more.")) || count(pm_human_flags("In today's fast-paced world, you need more.")) === 1, 'a stock opener is flagged');
});

t('Every writer goes through it', function () {
    $dirty = ['email_subject' => 'Direct bookings for Mufasa', 'email_body' => "Hello Grace,\n\nI hope this email finds you well. Our seamless tool will leverage your data \u{2014} great results!", 'whatsapp' => 'Hello Grace, we are thrilled to announce a seamless tool!! Call us!', 'opening' => "I came across Mufasa \u{2014} a lovely lodge. We are thrilled to announce a call."];
    $GLOBALS['PM_AI_STUB'] = fn() => json_encode([$dirty + ['id' => 'a1']]);
    $lead = ['id' => 'a1', 'brand' => 'promanaged', 'name' => 'Mufasa Lodge', 'type' => 'lodge', 'city' => 'Zomba', 'contact' => 'Grace Phiri', 'status' => 'qualified', 'drafts' => [], 'sent' => [], 'notes' => [], 'evidence' => []];
    $r = pm_agent_write([$lead])[0];
    pm_t_assert(pm_human_flags($r['email_body']) === [] && !str_contains($r['email_body'], "\u{2014}") && str_contains($r['email_body'], 'Our easy tool will use your data, great results.'), 'the first email: ' . str_replace("\n", ' / ', $r['email_body']));
    pm_t_assert(pm_human_flags($r['whatsapp'], 'whatsapp') === [] && substr_count($r['whatsapp'], '!') <= 1, 'the WhatsApp message: ' . $r['whatsapp']);
    $f = pm_agent_followup([$lead + ['drafts' => ['email_body' => 'old']]])[0];
    pm_t_assert(pm_human_flags($f['email_body']) === [], 'the follow-up');
    $w = pm_agent_winback([$lead])[0];
    pm_t_assert(pm_human_flags($w['email_body']) === [], 'the win-back');
    $GLOBALS['PM_AI_STUB'] = fn() => json_encode(['reply' => "Thanks for asking \u{2014} we can help!! Message us on WhatsApp!"]);
    $rep = pm_agent_social_reply(['post' => 'p', 'from' => 'Peter', 'message' => 'How much?']);
    pm_t_assert(!str_contains($rep, "\u{2014}") && substr_count($rep, '!') <= 1, 'the reply to a comment: ' . $rep);
    $GLOBALS['PM_AI_STUB'] = fn() => json_encode(['subject' => 'Hello', 'body' => "A note \u{2014} seamless! Really!"]);
    $c = pm_agent_compose($lead, 'email', 'human', 'short', 'need', 'english');
    pm_t_assert(pm_human_flags($c['body']) === [], 'a message made on request: ' . $c['body']);
    $GLOBALS['PM_AI_STUB'] = fn() => json_encode(['intent' => 'interested', 'summary' => 's', 'needs_human' => false, 'reply_subject' => 'Re: hello!', 'reply_body' => "Happy to help \u{2014} seamless setup! Shall we talk?", 'whatsapp' => 'Happy to help!! Shall we talk?', 'resume_on' => '', 'next_step' => 'x']);
    $a = pm_agent_reply($lead, 'Tell me more', 'email');
    pm_t_assert(pm_human_flags($a['reply_body']) === [] && !str_contains($a['reply_subject'], '!') && substr_count($a['whatsapp'], '!') <= 1, 'the answer to a reply: ' . $a['reply_body']);
    $GLOBALS['PM_AI_STUB'] = null;
});

t('The writers are told the rules', function () {
    $seen = [];
    $GLOBALS['PM_AI_STUB'] = function ($sys) use (&$seen) {
        $seen[] = $sys;
        return json_encode([['id' => 'a1', 'email_subject' => 's', 'email_body' => 'Hello, a plain note.', 'whatsapp' => 'Hello.']]);
    };
    $lead = ['id' => 'a1', 'brand' => 'promanaged', 'name' => 'Mufasa Lodge', 'type' => 'lodge', 'city' => 'Zomba', 'contact' => '', 'status' => 'qualified', 'drafts' => [], 'sent' => [], 'notes' => [], 'evidence' => []];
    pm_agent_write([$lead]);
    pm_agent_followup([$lead + ['drafts' => ['email_body' => 'old']]]);
    pm_agent_winback([$lead]);
    pm_brand_set('travel');
    pm_agent_write([$lead + ['brand' => 'travel']]);
    pm_brand_set('promanaged');
    foreach ($seen as $i => $s) {
        pm_t_assert(str_contains($s, 'Never use: delve, leverage, seamless') && str_contains($s, 'No em dashes'), 'writer ' . ($i + 1) . ' carries the rules');
    }
    pm_t_eq(count($seen), 4, 'first email, follow-up, win-back and Travel Malawi');
    $GLOBALS['PM_AI_STUB'] = null;
});

pm_t_done();
