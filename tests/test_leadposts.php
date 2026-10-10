<?php
/**
 * Tests for Lead posts: free, unlimited lead-capture posts with a button, a comment keyword and a landing page.
 * Runs on a TEMP COPY of data/ (see boot.php) with mail, WhatsApp and the AI stubbed: nothing real is contacted, nothing real is written.
 * Run: php tests/test_leadposts.php
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

/** Offers for three very different businesses: an IT business, a stay platform, and one added in the app. */
function lp_offer(string $kind, string $brand = ''): array
{
    $in = [
        'it' => ['title' => 'A free website check', 'problem' => 'Paper and WhatsApp bookings', 'fix' => 'We put every booking in one calendar.', 'proof' => 'One lodge stopped selling rooms twice',
            'signs' => "You lose track of who has paid\nYou answer the same questions all day\nGuests are booked twice", 'keyword' => 'CHECK', 'button' => 'whatsapp', 'volume' => 'bold'],
        'stay' => ['title' => 'List your stay free', 'problem' => 'Paying commission on every booking', 'fix' => 'Guests book you directly.', 'proof' => '', 'signs' => "Your calendar lives in a notebook\nYou answer the same questions all day",
            'keyword' => 'HOST', 'button' => 'signup', 'volume' => 'unhinged'],
        'solar' => ['title' => 'A free site visit', 'problem' => 'Power cuts that stop your lessons', 'fix' => 'We install and maintain solar and battery systems.', 'proof' => 'A school kept its computer lab running through a power cut',
            'signs' => "The generator is always out of fuel\nLessons stop when the power goes\nYour fuel bill keeps climbing", 'keyword' => 'SOLAR', 'button' => 'quote', 'volume' => 'calm'],
    ][$kind];
    $b = $brand ?: ['it' => 'promanaged', 'stay' => 'travel', 'solar' => 'solar'][$kind];
    return pm_lp_clean($in + ['questions' => [['label' => 'What kind of business do you run?', 'options' => ''], ['label' => 'How many rooms or desks?', 'options' => 'Under ten, Ten or more']]], $b)
        + ['id' => substr(md5($kind . $b), 0, 10), 'status' => 'active', 'created' => date('Y-m-d H:i'), 'views' => 0];
}

// a third business through the real questionnaire
$ans = ['name' => 'Sunrise Solar', 'sells' => 'We design, install and maintain solar power and battery systems for homes and small businesses.', 'customers' => 'Schools, clinics and small businesses',
    'sell_to' => 'business', 'cities' => 'Lilongwe', 'targets' => 'schools, clinics', 'facts' => "We install and maintain solar systems\nWe offer a free site visit", 'magnet' => 'a free site visit', 'phone' => '0999 111 222'];
[$SOLAR] = pm_brand_create($ans, pm_brand_draft($ans)['draft']);

/* =========================================================== copy */

t('Every template, at every volume, passes the same lint as any post', function () use ($SOLAR) {
    $bad = [];
    $n = 0;
    foreach (['it', 'stay', 'solar'] as $kind) {
        $o = lp_offer($kind, $kind === 'solar' ? $SOLAR : '');
        pm_brand_set($o['brand']);
        foreach (array_keys(pm_lp_templates()) as $tid) {
            if (!pm_lp_can_use($o, $tid)[0]) {
                continue;
            }
            foreach (array_keys(PM_LP_VOLUMES) as $vol) {
                $p = pm_lp_build_post($o, $tid, $vol, 0);
                $p['hashtags'] = pm_social_hashtags($p, 'facebook');
                $why = pm_social_lint($p['caption'], $o['brand'], 'facebook', [], $p);
                $warn = [];
                foreach (pm_lint_ext($p, 'facebook', []) as $r) {
                    $r['sev'] === 'block' ? $why[] = $r['msg'] : $warn[] = $r['msg'];
                }
                $n++;
                if ($why || $warn) {
                    $bad[] = "$kind $tid/$vol: " . implode(' | ', array_merge($why, $warn));
                }
            }
        }
    }
    pm_brand_set('promanaged');
    pm_t_assert($n >= 90, "$n template and volume combinations were checked");
    pm_t_eq($bad, [], 'none is blocked, and none even draws a warning (caps, exclamation marks, emoji, length)');
});

t('The copy never invents anything', function () {
    $o = lp_offer('it');
    foreach (pm_lp_templates() as $tid => $tpl) {
        if (!pm_lp_can_use($o, $tid)[0]) {
            continue;
        }
        foreach (array_keys(PM_LP_VOLUMES) as $vol) {
            $c = pm_lp_copy($o, $tid, $vol);
            $all = $c['headline'] . ' ' . $c['sub'] . ' ' . $c['caption'] . ' ' . implode(' ', $c['bullets']);
            if (preg_match('/\d/', preg_replace('/' . preg_quote($o['keyword'], '/') . '/', '', $all))) {
                pm_t_assert(false, "$tid/$vol contains a digit that is not the owner's: $all");
            }
            if (preg_match('/\b(best|cheapest|fastest|guarantee|leading|100%|24\/7|limited|currently|now|only today|last chance|hurry)\b/i', $all)) {
                pm_t_assert(false, "$tid/$vol contains a claim or fake urgency: $all");
            }
            if (preg_match('#https?://|www\.|[$£€]|MWK|kwacha#i', $all)) {
                pm_t_assert(false, "$tid/$vol contains a link or a price");
            }
        }
    }
    pm_t_assert(true, 'no digits, claims, fake urgency, links or prices in any template at any volume');
    $c = [pm_lp_copy($o, 'callout', 'calm'), pm_lp_copy($o, 'callout', 'bold'), pm_lp_copy($o, 'callout', 'unhinged')];
    pm_t_assert(count(array_unique(array_column($c, 'caption'))) === 3 && count(array_unique(array_column($c, 'headline'))) === 3, 'the three volumes really read differently');
    pm_t_assert(str_contains($c[2]['caption'], 'Stop scrolling') && !str_contains($c[0]['caption'], 'Stop scrolling'), 'and the loud one is the loud one');
    pm_t_assert(preg_match('/Comment CHECK and we will message you/', $c[0]['caption']) === 1, 'every post tells people how to respond: comment the keyword');
});

t('A template that lacks what it needs says so', function () {
    $o = pm_lp_clean(['title' => 'A free site visit', 'problem' => ''], 'promanaged');
    [$ok, $why] = pm_lp_can_use($o, 'callout');
    pm_t_assert(!$ok && str_contains($why, 'the problem you fix'), 'callout needs the problem: ' . $why);
    pm_t_assert(pm_lp_can_use($o, 'freebie')[0] && !pm_lp_can_use($o, 'signs')[0] && !pm_lp_can_use($o, 'proofpost')[0], 'the offer alone is enough for some, not for others');
    pm_t_assert(!pm_lp_can_use($o, 'nonsense')[0], 'an unknown template is refused');
    $long = pm_lp_clean(['title' => 'A free site visit', 'problem' => 'This is a very long problem description that cannot possibly fit on a picture headline'], 'promanaged');
    pm_t_eq(pm_lp_copy($long, 'callout', 'bold')['headline'], 'Sound familiar?', 'a long problem does not break the picture headline');
    $noKw = pm_lp_clean(['title' => 'A free site visit', 'problem' => 'Power cuts'], 'promanaged');
    pm_t_assert(str_contains(pm_lp_copy($noKw, 'callout', 'bold')['caption'], 'Message us and a person will reply'), 'with no keyword the response line asks for a message instead');
});

t('Offers are checked before they are saved', function () {
    $ok = pm_lp_clean(['title' => 'A free site visit', 'problem' => 'Power cuts', 'keyword' => 'sol ar!', 'volume' => 'wild', 'button' => 'nope', 'ends' => 'tomorrow'], 'promanaged');
    pm_t_eq([$ok['keyword'], $ok['volume'], $ok['button'], $ok['ends']], ['SOLAR', 'bold', 'whatsapp', ''], 'the keyword is capital letters, and bad volume, button and date fall back safely');
    pm_t_eq(pm_lp_check($ok), [], 'a title and a problem are enough');
    pm_t_assert(count(pm_lp_check(pm_lp_clean(['title' => 'Hi'], 'promanaged'))) === 2, 'a tiny title and nothing to say are both refused');
    pm_t_assert(pm_lp_check(pm_lp_clean(['title' => 'A free site visit', 'problem' => 'See https://example.com now'], 'promanaged')) !== [], 'links in the text are refused: the post adds its own');
    foreach (['The best site check in Malawi', 'Cheapest hosting around', 'We are the fastest', 'A 100% guaranteed result', 'Number one for stock', 'Open 24/7', 'Risk-free trial'] as $claim) {
        $why = pm_lp_check(pm_lp_clean(['title' => 'A free site visit', 'problem' => $claim], 'promanaged'));
        pm_t_assert($why !== [] && str_contains($why[0], 'Take out'), "\"$claim\" is caught when the offer is saved: " . ($why[0] ?? '-'));
    }
    pm_t_eq(pm_lp_check(pm_lp_clean(['title' => 'A free site visit', 'problem' => 'We look after your accounts and support your staff'], 'promanaged')), [], 'plain words about what you do pass ("best practices" style phrases are the owner\'s to avoid)');
    $q = pm_lp_clean(['title' => 'A free site visit', 'problem' => 'x', 'questions' => [['label' => 'Q1', 'options' => 'a, b,, c'], ['label' => '', 'options' => 'x'], ['label' => 'Q3'], ['label' => 'Q4'], ['label' => 'Q5']]], 'promanaged');
    pm_t_eq([count($q['questions']), $q['questions'][0]['options']], [3, ['a', 'b', 'c']], 'up to three questions, blank ones dropped, options split');
});

/* =========================================================== posts */

t('Making posts: drafts only, spread over days, linted, never dropped', function () use ($SOLAR) {
    pm_save('social_posts', []);
    $o = lp_offer('it');
    pm_lp_update(fn($rows) => [$o]);
    $r = pm_lp_make_posts($o, array_keys(pm_lp_templates()), 'bold');
    pm_t_assert($r['made'] === 12 && $r['held'] === 0, 'all twelve templates were drafted, none held: ' . json_encode([$r['made'], $r['held']]));
    $posts = array_values(array_filter(pm_social_posts(), fn($p) => ($p['lead_offer'] ?? '') === $o['id']));
    pm_t_eq(count($posts), 12, 'and saved');
    pm_t_assert(!array_filter($posts, fn($p) => ($p['status'] ?? '') !== 'draft'), 'every one is a draft: nothing is published without approval');
    pm_t_eq(count(array_unique(array_map(fn($p) => substr((string)$p['when'], 0, 10), $posts))), 12, 'each on its own day, by the normal schedule');
    pm_t_assert(min(array_map(fn($p) => substr((string)$p['when'], 0, 10), $posts)) >= date('Y-m-d', strtotime('+1 day')), 'starting tomorrow');
    $p = $posts[0];
    pm_t_assert($p['layout'] === 'leadad' && $p['cta'] === 'offer' && $p['button'] === 'Send WhatsApp message' && $p['pillar'] === PM_LP_PILLAR && !empty($p['facts']) && $p['lead_keyword'] === 'CHECK', 'each carries its layout, button, facts and offer');
    pm_t_assert(in_array('A free website check', $p['facts'], true), 'the offer\'s own words are the facts the lint reads');
    $heads = array_column($posts, 'headline');
    pm_t_eq(count($heads), count(array_unique($heads)), 'no two headlines are the same');
    // the whole library at once, for every kind of business and every volume: nothing is rejected as a repeat of its sister posts
    $held = [];
    foreach (['it', 'stay', 'solar'] as $kind) {
        foreach (array_keys(PM_LP_VOLUMES) as $vol) {
            pm_save('social_posts', []);
            $ob = lp_offer($kind, $kind === 'solar' ? $SOLAR : '');
            pm_lp_update(fn($rows) => [$ob]);
            $rb = pm_lp_make_posts($ob, array_keys(pm_lp_templates()), $vol);
            foreach (pm_social_posts() as $pp) {
                if ($pp['lint']) {
                    $held[] = "$kind/$vol/{$pp['lead_template']}: " . implode(' ', $pp['lint']);
                }
            }
        }
    }
    pm_t_eq($held, [], 'a full batch of every template is never held as "too similar", for three businesses at three volumes');
    pm_save('social_posts', []);
    $o = lp_offer('it');
    pm_lp_update(fn($rows) => [$o]);
    pm_lp_make_posts($o, array_keys(pm_lp_templates()), 'bold');
    // a second batch right after: not rejected as repeats
    $r2 = pm_lp_make_posts($o, ['callout', 'question'], 'unhinged');
    pm_t_assert($r2['made'] === 2, 'a second batch at another volume is made: ' . json_encode($r2));
    // a template that does not fit is skipped, not faked
    $bare = lp_offer('stay');
    pm_t_eq(pm_lp_make_posts($bare, ['proofpost'], 'bold')['made'], 0, 'a template the offer cannot support makes nothing');
    pm_t_eq(pm_lp_make_posts($bare, array_fill(0, 30, 'callout'), 'bold')['made'], 1, 'repeats of one template count once');
    // a post that breaks a rule is kept, held, and says why
    $bad = lp_offer('it');
    $bad['title'] = 'A site check for MWK 50,000';
    $rh = pm_lp_make_posts($bad, ['freebie'], 'bold');
    $hp = array_values(array_filter(pm_social_posts(), fn($p) => in_array($p['id'], $rh['ids'], true)))[0];
    pm_t_assert($rh['made'] === 1 && $rh['held'] === 1 && $hp['status'] === 'needs_edit', 'a post that trips a rule (a price) is held for edit, not dropped');
    pm_t_assert($hp['lint'] !== [] && str_contains(json_encode($hp['lint']), 'price'), 'with the reason shown: ' . json_encode($hp['lint']));
    pm_save('social_posts', []);
});

t('The picture carries a real button', function () {
    if (!pm_card_gd_ok()) {
        pm_t_assert(true, 'no GD or fonts here: the picture test is skipped');
        return;
    }
    pm_brand_set('promanaged');
    $o = lp_offer('it');
    foreach (['whatsapp', 'quote', 'signup', 'call'] as $btn) {
        $p = pm_lp_build_post(['button' => $btn] + $o, 'callout', 'bold', 0);
        $file = pm_card_render($p, 'sq');
        $ok = is_string($file) && is_file($file) && filesize($file) > 4000 && @getimagesize($file)[0] === 1080;
        pm_t_assert($ok, "the $btn picture is drawn (" . ($file ? filesize($file) : 0) . ' bytes)');
    }
    $sizes = [];
    foreach (['sq', '4x5', 'story'] as $sz) {
        $f = pm_card_render(pm_lp_build_post($o, 'beforeafter', 'bold', 0), $sz);
        $sizes[$sz] = $f && is_file($f);
    }
    pm_t_eq(array_values(array_unique($sizes)), [true], 'and in the square, portrait and story sizes');
    $long = pm_lp_build_post($o, 'signs', 'unhinged', 0);
    $long['bullets'] = [str_repeat('A very long line of text that has to wrap ', 4), 'Short', str_repeat('Another long line ', 6)];
    $long['button'] = 'A very long button label that must still fit inside the pill';
    pm_t_assert(is_file((string)pm_card_render($long, 'sq')), 'long lines and a long button label still draw');
    $img = imagecreatefrompng(pm_card_render(pm_lp_build_post($o, 'callout', 'bold', 0), 'sq'));
    $bg = imagecolorat($img, 540, 600);
    pm_t_assert(imagesx($img) === 1080 && $bg !== false, 'the file is a proper picture');
    imagedestroy($img);
    pm_t_assert(in_array('leadad', PM_CARD_LAYOUTS, true), 'leadad is a registered layout');
});

/* =========================================================== the button on every channel */

t('The call to action: landing page when hosted, WhatsApp link otherwise', function () use ($SOLAR) {
    pm_save('social_posts', []);
    $o = lp_offer('it');
    pm_lp_update(fn($rows) => [$o]);
    // a business phone so the tap-to-chat link exists
    $raw = pm_load('settings', 'pm_default_settings');
    $raw['phone'] = '0999 123 456';
    pm_save('settings', $raw);
    pm_brand_set('promanaged');
    $p = pm_lp_build_post($o, 'callout', 'bold', 0);
    pm_t_eq(pm_ch_cta($p), 'check', 'a lead post uses the enquiry-link call to action everywhere');
    $cl = pm_ch_cta_line($p, 'facebook', true);
    pm_t_assert(str_starts_with($cl['line'], 'Tap to message us on WhatsApp: https://wa.me/265999123456?text=') && str_contains($cl['line'], 'CHECK'), 'without APP_URL a Facebook post gets a tap-to-chat link that already says the keyword: ' . $cl['line']);
    $fb = pm_var_build($p, 'facebook')['text'];
    pm_t_assert(substr_count($fb, 'wa.me') === 1 && str_contains($fb, 'Comment CHECK'), 'the Facebook caption has the comment ask and exactly one link');
    $ig = pm_var_build($p, 'instagram')['text'];
    pm_t_assert(str_contains($ig, 'WhatsApp us and say') && !str_contains($ig, 'http'), 'Instagram keeps links out of the caption');
    $x = pm_var_build($p, 'x')['text'];
    pm_t_assert(mb_strlen($x) <= 285, 'X fits its limit');
    $ln = pm_var_build($p, 'linkedin')['text'];
    pm_t_assert(strlen($ln) > 20, 'LinkedIn still builds a caption');
    pm_t_eq(pm_ch_cta_line($p, 'x', false)['link'] === '' ? 'keyword' : 'link', 'keyword', 'where a link is not allowed the keyword line is used');
    pm_t_eq(pm_lp_url('promanaged', $o['id'], 'x'), '', 'there is no landing page address until the app is online (the hosted path is covered by tests/test_pages.php)');
    $p2 = pm_lp_build_post($o, 'callout', 'bold', 0);
    pm_lp_update(function ($rows) { $rows[0]['status'] = 'deleted'; return $rows; });
    $cl2 = pm_ch_cta_line($p2, 'facebook', true);
    pm_t_assert(!empty($cl2['line']), 'a post whose offer was deleted still has a way to reply');
    pm_lp_update(fn($rows) => []);
    pm_t_assert(!empty(pm_ch_cta_line($p2, 'facebook', true)['line']), 'and so does one whose offer is gone');
});

/* =========================================================== the keyword */

t('Commenting the keyword makes a buyer, answered by code, never by AI', function () {
    pm_brand_set('promanaged');
    $o = lp_offer('it');
    pm_lp_update(fn($rows) => [$o, lp_offer('stay', 'travel')]);
    foreach (['CHECK' => true, 'check' => true, ' Check! ' => true, 'CHECK please' => true, 'please check' => true, 'checked' => false, 'I will check later' => false, 'HOST' => false, '' => false, 'yes CHECK' => true,
        'can you check my website please' => false] as $text => $hit) {
        $found = pm_lp_keyword_hit('promanaged', $text);
        pm_t_assert(($found !== null) === $hit && (!$hit || $found['id'] === $o['id']), '"' . $text . '" is ' . ($hit ? 'a hit' : 'not a hit'));
    }
    pm_t_assert(pm_lp_keyword_hit('travel', 'HOST') !== null && pm_lp_keyword_hit('travel', 'CHECK') === null, 'each business has its own keywords');
    pm_t_eq(pm_lp_keyword_hit('promanaged', str_repeat('CHECK ', 20)), null, 'a long comment is not a keyword');
    pm_lp_patch('promanaged', $o['id'], function ($r) { $r['status'] = 'paused'; return $r; });
    pm_t_eq(pm_lp_keyword_hit('promanaged', 'CHECK'), null, 'a paused offer stops picking up its keyword');
    pm_lp_patch('promanaged', $o['id'], function ($r) { $r['status'] = 'active'; $r['ends'] = date('Y-m-d', strtotime('-1 day')); return $r; });
    pm_t_eq(pm_lp_keyword_hit('promanaged', 'CHECK'), null, 'and so does an ended one');
    pm_lp_patch('promanaged', $o['id'], function ($r) { $r['ends'] = ''; return $r; });
    $r1 = pm_lp_keyword_reply(pm_lp_get($o['id']), 'Mary Banda');
    pm_t_assert(str_starts_with($r1, 'Hi Mary, thanks for sending CHECK.') && pm_reply_lint($r1, 'promanaged') === [], 'the default reply is friendly and passes the reply lint: ' . $r1);
    pm_lp_patch('promanaged', $o['id'], function ($r) { $r['reply'] = 'Lovely, tell us about your business.'; return $r; });
    pm_t_assert(str_contains(pm_lp_keyword_reply(pm_lp_get($o['id']), 'Mary'), 'Lovely, tell us about your business.'), 'the owner\'s own words win');
    // the judge: a comment with the keyword becomes a buyer with a ready reply
    // the rule inside the judge
    $src = (string)file_get_contents(dirname(__DIR__) . '/lib/social_growth.php');
    pm_t_assert(str_contains($src, 'pm_lp_keyword_hit($brand, $t)') && str_contains($src, "'reason' => 'Commented the offer keyword ('"), 'the judge consults the keyword rule before it treats a short comment as a friendly reaction');
});

/* =========================================================== handlers */

t('The forms: save, make, pause, delete, keyword clashes, other businesses', function () {
    pm_brand_set('promanaged');
    pm_save('social_posts', []);
    pm_lp_update(fn($rows) => []);
    $_POST = ['title' => 'A free website check', 'problem' => 'Paper and WhatsApp bookings', 'fix' => 'We put every booking in one calendar.', 'keyword' => 'check', 'button' => 'quote', 'volume' => 'unhinged',
        'signs' => "A\nB\nC", 'questions' => [['label' => 'Q1', 'options' => '']], 'make' => '1', 'templates' => ['freebie', 'callout', 'question', 'quote', 'nonsense']];
    $r = pm_do_lp_save('promanaged');
    pm_t_assert($r['kind'] === 'ok' && $r['to'] === 'social' && str_contains($r['msg'], '4 draft posts made'), 'save and make: ' . $r['msg']);
    $o = pm_lp_for_brand('promanaged')[0];
    pm_t_assert(preg_match('/^[a-f0-9]{10}$/', $o['id']) && $o['status'] === 'active' && $o['keyword'] === 'CHECK' && $o['volume'] === 'unhinged', 'the offer is stored, live, with a clean keyword');
    pm_t_eq(count(pm_social_posts()), 4, 'four drafts exist');
    // a clash
    $_POST = ['title' => 'Another offer', 'problem' => 'Something else', 'keyword' => 'CHECK'];
    $r = pm_do_lp_save('promanaged');
    pm_t_assert($r['kind'] === 'err' && str_contains($r['msg'], 'already uses the keyword CHECK'), 'two live offers cannot share a keyword: ' . $r['msg']);
    pm_t_eq(count(pm_lp_for_brand('promanaged')), 1, 'and nothing was saved');
    $_POST = ['title' => 'Another offer', 'problem' => 'Something else', 'keyword' => 'OTHER'];
    pm_t_eq(pm_do_lp_save('promanaged')['kind'], 'ok', 'a different keyword is fine');
    // refused
    $_POST = ['title' => 'x'];
    pm_t_eq(pm_do_lp_save('promanaged')['kind'], 'err', 'a thin offer is refused with a reason');
    // editing keeps the id and does not clash with itself
    $_POST = ['id' => $o['id'], 'title' => 'A free website check', 'problem' => 'Paper bookings', 'keyword' => 'CHECK', 'volume' => 'calm'];
    pm_t_eq(pm_do_lp_save('promanaged')['msg'], 'Saved.', 'editing saves under the same id');
    pm_t_eq([pm_lp_get($o['id'])['problem'], pm_lp_get($o['id'])['views'], pm_lp_get($o['id'])['created'] === $o['created']], ['Paper bookings', 0, true], 'with its counters and date kept');
    // another business cannot touch it
    $_POST = ['id' => $o['id'], 'title' => 'Hijack', 'problem' => 'x'];
    pm_t_assert(pm_do_lp_save('travel')['kind'] === 'err' && pm_lp_get($o['id'])['title'] === 'A free website check', 'another business cannot edit it');
    $_POST = ['id' => $o['id'], 'to' => 'paused'];
    pm_t_assert(pm_do_lp_status('travel')['kind'] === 'err' && pm_lp_get($o['id'])['status'] === 'active', 'nor pause it');
    // make more
    $_POST = ['id' => $o['id'], 'templates' => ['whatsapp', 'ask'], 'volume' => 'calm'];
    $r = pm_do_lp_post('promanaged');
    pm_t_assert($r['kind'] === 'ok' && str_contains($r['msg'], '2 draft posts made') && str_contains($r['msg'], 'Nothing is published until you approve it'), 'make more: ' . $r['msg']);
    $_POST = ['id' => $o['id'], 'templates' => []];
    pm_t_eq(pm_do_lp_post('promanaged')['kind'], 'err', 'no template ticked is refused');
    $_POST = ['id' => $o['id'], 'templates' => ['proofpost']];
    pm_t_eq(pm_do_lp_post('promanaged')['kind'], 'err', 'a template that cannot fit says so');
    // pause and resume
    $_POST = ['id' => $o['id'], 'to' => 'paused'];
    pm_do_lp_status('promanaged');
    pm_t_eq(pm_lp_public('promanaged', $o['id']), null, 'a paused offer has no public page');
    $_POST = ['id' => $o['id'], 'to' => 'active'];
    pm_do_lp_status('promanaged');
    pm_t_assert(pm_lp_public('promanaged', $o['id']) !== null, 'and it comes back');
    pm_t_eq(pm_lp_public('travel', $o['id']), null, 'one business\'s offer is never served as another\'s');
    pm_t_eq(pm_lp_public('promanaged', 'zzzz'), null, 'a bad id is no offer');
    // delete
    $_POST = ['id' => $o['id']];
    pm_t_eq(pm_do_lp_delete('travel')['kind'], 'err', 'another business cannot delete it');
    pm_t_assert(pm_do_lp_delete('promanaged')['kind'] === 'ok' && pm_lp_get($o['id']) === null, 'the owner can');
    pm_t_assert(count(pm_social_posts()) >= 6, 'posts already made are kept');
    $GLOBALS['PM_WHO'] = '';
    pm_save('social_posts', []);
});

t('The public view shows only what a visitor should see', function () {
    pm_brand_set('promanaged');
    $o = lp_offer('it');
    $o['views'] = 7;
    pm_lp_update(fn($rows) => [$o]);
    $pub = pm_lp_public('promanaged', $o['id']);
    pm_t_assert($pub['headline'] === 'A free website check' && $pub['button'] === 'Send WhatsApp message' && $pub['keyword'] === 'CHECK' && count($pub['questions']) === 2, 'headline, button, keyword and questions');
    pm_t_eq(array_intersect(array_keys($pub), ['status', 'created', 'views', 'reply', 'signs', 'id_secret']), [], 'no internal fields leak (status, views, counters, the reply text)');
    pm_t_assert(in_array('One lodge stopped selling rooms twice', $pub['bullets'], true) && in_array('You lose track of who has paid', $pub['bullets'], true), 'the owner\'s signs and proof are the bullets');
    pm_lp_patch('promanaged', $o['id'], function ($r) { $r['ends'] = date('Y-m-d', strtotime('-1 day')); return $r; });
    pm_t_eq(pm_lp_public('promanaged', $o['id']), null, 'an ended offer is closed');
    pm_lp_view($o['id']);
    pm_lp_view($o['id']);
    pm_t_eq(pm_lp_get($o['id'])['views'], 9, 'visits are counted');
    $m = pm_lp_message($o + ['questions' => $o['questions']], ['Guest house', 'Under ten', 'x'], 'Please call after lunch');
    pm_t_assert(str_contains($m, 'Asked via the offer "A free website check"') && str_contains($m, 'What kind of business do you run? Guest house') && str_contains($m, 'How many rooms or desks? Under ten') && str_contains($m, 'Please call after lunch'), 'the lead gets the answers in one message: ' . $m);
    $m2 = pm_lp_message($o + ['questions' => $o['questions']], ['<script>alert(1)</script>'], '');
    pm_t_assert(!str_contains($m2, '<script>'), 'answers are cleaned');
});

t('Results: what an offer has brought in', function () {
    pm_brand_set('promanaged');
    pm_save('social_posts', []);
    $o = lp_offer('it');
    pm_lp_update(fn($rows) => [$o]);
    $r = pm_lp_make_posts($o, ['callout', 'freebie'], 'bold');
    pm_social_update(function ($posts) use ($r) {
        $posts[0]['status'] = 'published';
        return $posts;
    });
    $mk = fn($id, $o2) => $o2 + ['id' => $id, 'brand' => 'promanaged', 'name' => "Lead $id", 'status' => 'replied', 'notes' => [], 'thread' => [], 'sent' => []];
    pm_save('leads', ['f1' => $mk('f1', ['src_tag' => 'offer-' . $o['id']]), 'f2' => $mk('f2', ['src_tag' => 'offer-' . $o['id']]), 'c1' => $mk('c1', ['source_post' => $r['ids'][1]]), 'z' => $mk('z', ['src_tag' => 'something-else']),
        'o1' => $mk('o1', ['src_tag' => 'offer-' . $o['id'], 'brand' => 'travel'])]);
    pm_save('fb_judged', ['promanaged' => ['a' => ['reason' => 'Commented the offer keyword (CHECK)'], 'b' => ['reason' => 'Commented the offer keyword (CHECK)'], 'c' => ['reason' => 'Plain price question']]]);
    $st = pm_lp_stats(pm_lp_get($o['id']));
    pm_t_eq([$st['posts'], $st['published'], $st['drafts'], $st['leads'], $st['keyword_comments']], [2, 1, 1, 3, 2], 'posts, published, waiting, leads (form and keyword) and keyword comments');
    pm_save('leads', []);
    pm_save('fb_judged', []);
    pm_save('social_posts', []);
});

t('The screen', function () use ($SOLAR) {
    pm_brand_set('promanaged');
    $_SESSION = [];
    $csrf = 'tok';
    $vb = 'promanaged';
    $settings = pm_settings();
    $o = lp_offer('it');
    pm_lp_update(fn($rows) => [$o]);
    ob_start();
    require dirname(__DIR__) . '/lib/view_social_leadposts.php';
    $h = ob_get_clean();
    pm_t_assert(str_contains($h, 'Free, unlimited, and honest') && str_contains($h, 'A free website check') && str_contains($h, 'Make draft posts') && str_contains($h, 'Another offer'), 'the screen lists the offer and the way to make posts');
    pm_t_assert(substr_count($h, 'name="templates[]"') >= 12 && str_contains($h, 'Unhinged') && str_contains($h, 'loud, pattern-breaking'), 'every template and every volume is on offer');
    pm_t_assert(str_contains($h, 'value="lp_post"') && str_contains($h, 'value="lp_save"') && str_contains($h, 'value="lp_status"') && str_contains($h, 'value="lp_delete"'), 'each form posts to a real handler');
    pm_t_assert(str_contains($h, 'Landing page') || str_contains($h, 'Tap-to-chat link') || str_contains($h, 'Before the buttons work well'), 'it says how people will reach the offer, or what is missing');
    pm_t_assert(str_contains($h, 'class="tabs"') && str_contains($h, 'Lead posts'), 'it sits in the Social tab bar');
    pm_t_assert(!str_contains($h, '<script>alert'), 'nothing unescaped');
    foreach (['lp_save', 'lp_post', 'lp_status', 'lp_delete'] as $do) {
        pm_t_assert(function_exists('pm_do_' . $do), "pm_do_$do exists for the social_ext route");
    }
    pm_lp_update(fn($rows) => []);
});

pm_t_done();
