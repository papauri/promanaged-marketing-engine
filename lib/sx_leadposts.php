<?php
/**
 * sx_leadposts — lead-capture posts ("ads" that cost nothing): an OFFER (what you give, the problem it fixes, a button and a keyword) becomes a
 * set of bold, ready-to-approve posts with a picture that carries a real-looking button, a comment keyword that turns commenters into leads, and a
 * landing page with big buttons and a short form (enquire.php?mode=offer). Everything is organic and free; paid reach is the Ads screen.
 *
 * Honest by construction: every post goes through the same lint as any other (no invented numbers, prices, claims, guarantees or fake urgency),
 * lands as a DRAFT in the normal approval queue, and the only facts it may use are the owner's own words. "Unhinged" is the energy of the copy,
 * never a licence to say something untrue.
 *
 * Data: data/lead_offers.json {rows:[{id,brand,title,problem,fix,proof,signs[],button,keyword,volume,ends,questions[{label,options[]}],reply,status,
 * created,views}]}.  The store and the public page live in lib/leadoffers.php.  Handlers: POST action=social_ext&do=lp_save|lp_post|lp_status|lp_delete.  Screen: Social > Lead posts.
 */

require_once __DIR__ . '/leadoffers.php';


/* ---------------- storage ---------------- */

/* ---------------- the offer ---------------- */

/** Cleans what the owner typed into a complete offer record (no id or status: callers add those). */
function pm_lp_clean(array $in, string $brand): array
{
    $signs = array_values(array_filter(array_map(fn($x) => pm_lp_line($x, 90), preg_split('/\R/u', (string)($in['signs'] ?? '')) ?: [])));
    $qs = [];
    foreach ((array)($in['questions'] ?? []) as $q) {
        $lab = pm_lp_line($q['label'] ?? '', 80);
        if ($lab === '') {
            continue;
        }
        $opts = array_values(array_filter(array_map(fn($o) => pm_lp_line($o, 40), preg_split('/[\r\n,]+/u', is_array($q['options'] ?? '') ? implode(',', $q['options']) : (string)($q['options'] ?? '')) ?: [])));
        $qs[] = ['label' => $lab, 'options' => array_slice($opts, 0, 8)];
    }
    $kw = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)($in['keyword'] ?? '')));
    $ends = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['ends'] ?? '')) ? (string)$in['ends'] : '';
    return [
        'brand' => $brand, 'title' => pm_lp_line($in['title'] ?? '', 70), 'problem' => pm_lp_line($in['problem'] ?? '', 140), 'fix' => pm_lp_line($in['fix'] ?? '', 160),
        'proof' => pm_lp_line($in['proof'] ?? '', 160), 'signs' => array_slice($signs, 0, 4), 'button' => isset(PM_LP_BUTTONS[$in['button'] ?? '']) ? (string)$in['button'] : 'whatsapp',
        'keyword' => strlen($kw) >= 3 && strlen($kw) <= 10 ? $kw : '', 'volume' => isset(PM_LP_VOLUMES[$in['volume'] ?? '']) ? (string)$in['volume'] : 'bold', 'ends' => $ends,
        'questions' => array_slice($qs, 0, 3), 'reply' => pm_lp_line($in['reply'] ?? '', 240),
    ];
}

/** Problems with an offer (empty = fine). The only hard needs: something to offer and something to say about it. */
function pm_lp_check(array $o): array
{
    $bad = [];
    if (mb_strlen($o['title']) < 4) {
        $bad[] = 'Say what you are offering (at least a few words), for example "A free site visit".';
    }
    if ($o['problem'] === '' && $o['fix'] === '' && $o['proof'] === '') {
        $bad[] = 'Add at least one of: the problem you fix, what you do about it, or something that really happened.';
    }
    $all = implode("\n", array_merge([$o['title'], $o['problem'], $o['fix'], $o['proof']], (array)$o['signs']));
    if (preg_match('#https?://|www\.#i', $all)) {
        $bad[] = 'Take links out of the text: the post adds its own button and link.';
    }
    if (preg_match('/\b(best|cheapest|fastest|number (?:one|1)|100\s?%|risk[- ]free|24\s?\/\s?7|guarantee[sd]?)\b/i', $all, $m)) {
        $bad[] = 'Take out "' . $m[1] . '": it cannot be backed up, so the posts would be held. Say what you actually do instead.';
    }
    return $bad;
}

/** A starting point for a new offer from what the business already told the app. */
function pm_lp_defaults(string $brand): array
{
    $p = pm_brand_profile($brand);
    $title = $p['magnet'] !== '' ? ucfirst($p['magnet']) : '';
    $kw = $p['cta_keyword'] !== '' ? $p['cta_keyword'] : 'QUOTE';
    $off = (array)(pm_agents_config($brand)['offerings'] ?? []);
    return ['title' => $title, 'problem' => '', 'fix' => $off ? pm_lp_line(trim(explode(':', (string)$off[0], 2)[1] ?? (string)$off[0]), 120) : '', 'keyword' => $kw, 'button' => 'whatsapp', 'volume' => 'bold',
        'questions' => [['label' => 'What kind of business do you run?', 'options' => []], ['label' => 'What would help you most?', 'options' => []]]];
}

/** The facts the posts may rely on (the lint reads them): the offer's own words and the business's own facts. */
function pm_lp_facts(array $o): array
{
    $f = array_filter(array_merge([$o['title'], $o['problem'], $o['fix'], $o['proof']], (array)$o['signs'], preg_split('/\R/', (string)(pm_brain((string)$o['brand'])['facts'] ?? '')) ?: []), fn($x) => trim((string)$x) !== '');
    return array_slice(array_values(array_unique(array_map(fn($x) => trim((string)$x), $f))), 0, 12);
}

/* ---------------- the templates ---------------- */

/**
 * The library. need = what the offer must have for the template to make sense; engage = how people are asked to respond
 * (keyword | question | tag | save | ask). The copy itself is in pm_lp_copy().
 */
function pm_lp_templates(): array
{
    return [
        'callout' => ['label' => 'The callout', 'goal' => 'Name the problem so the right people stop scrolling.', 'need' => ['problem'], 'engage' => 'keyword'],
        'freebie' => ['label' => 'The offer', 'goal' => 'Put what you give away front and centre.', 'need' => ['title'], 'engage' => 'keyword'],
        'question' => ['label' => 'Hot-seat question', 'goal' => 'Get people talking in the comments, then pick up the ones who want help.', 'need' => ['problem'], 'engage' => 'question'],
        'beforeafter' => ['label' => 'Before and after', 'goal' => 'Show the problem next to what you do about it.', 'need' => ['problem', 'fix'], 'engage' => 'save'],
        'signs' => ['label' => 'Does this sound like you?', 'goal' => 'A checklist people recognise themselves in.', 'need' => ['signs'], 'engage' => 'save'],
        'tag' => ['label' => 'Tag the one who needs this', 'goal' => 'Reach friends of friends, no prize needed.', 'need' => ['problem'], 'engage' => 'tag'],
        'quote' => ['label' => 'Get a quote', 'goal' => 'A direct ask for people who are ready.', 'need' => ['title'], 'engage' => 'keyword'],
        'proofpost' => ['label' => 'Something that happened', 'goal' => 'A real result in your own words.', 'need' => ['proof'], 'engage' => 'keyword'],
        'howitworks' => ['label' => 'How it works', 'goal' => 'Take the fear out of the first step.', 'need' => ['title'], 'engage' => 'keyword'],
        'ask' => ['label' => 'Ask us anything', 'goal' => 'Invite questions, which are the warmest leads.', 'need' => ['title'], 'engage' => 'ask'],
        'challenge' => ['label' => 'The honest challenge', 'goal' => 'A small self-test that ends in a message to you.', 'need' => ['problem'], 'engage' => 'keyword'],
        'whatsapp' => ['label' => 'Skip the form', 'goal' => 'Pull people straight into WhatsApp.', 'need' => ['title'], 'engage' => 'keyword'],
    ];
}

/** Does the offer have everything the template needs? [ok, reason when not]. */
function pm_lp_can_use(array $o, string $tid): array
{
    $t = pm_lp_templates()[$tid] ?? null;
    if (!$t) {
        return [false, 'Unknown template.'];
    }
    foreach ($t['need'] as $n) {
        $have = $n === 'signs' ? count((array)$o['signs']) >= 2 : trim((string)($o[$n] ?? '')) !== '';
        if (!$have) {
            return [false, $n === 'signs' ? 'Needs at least two "signs" lines.' : 'Needs "' . ['title' => 'what you offer', 'problem' => 'the problem you fix', 'fix' => 'what you do about it', 'proof' => 'something that really happened'][$n] . '".'];
        }
    }
    return [true, ''];
}

/** The line that asks for the response. */
function pm_lp_engage_line(array $o, string $how, int $variant = 0): string
{
    $k = (string)$o['keyword'];
    $variant = abs($variant) % 3;
    $kw = $k !== '' ? ["Comment $k and we will message you.", "Want in? Comment $k and we will message you.", "Comment $k below, or send it on WhatsApp, and a person replies."][$variant] : 'Message us and a person will reply.';
    $then = $k !== '' ? ["Want a hand with it? Comment $k.", "Need a hand? Comment $k.", "Want us to help? Comment $k."][$variant] : 'Want a hand with it? Message us.';
    return match ($how) {
        'question' => "Tell us in the comments.\n\n$then",
        'tag' => 'Tag them below.' . ($k !== '' ? " They can comment $k to hear from us." : ''),
        'save' => "Save this so you can find it again.\n\n$then",
        'ask' => "Ask your question in the comments.\n\n$then",
        default => $kw,
    };
}

/**
 * The copy of one template at one volume: headline and sub (for the picture), caption, bullets, and the engagement type.
 * Volume changes the energy only: calm is plain, bold is direct, unhinged is loud and pattern-breaking. It never adds a claim, number, price or deadline.
 */
function pm_lp_copy(array $o, string $tid, string $vol): array
{
    $vol = isset(PM_LP_VOLUMES[$vol]) ? $vol : 'bold';
    $tpl = pm_lp_templates()[$tid] ?? pm_lp_templates()['callout'];
    $T = pm_lp_tr((string)$o['title']);
    $P = pm_lp_tr((string)$o['problem']);
    $F = trim((string)$o['fix']);
    $PR = pm_lp_tr((string)$o['proof']);
    $K = (string)$o['keyword'];
    $signs = array_slice((array)$o['signs'], 0, 3);
    $eng = pm_lp_engage_line($o, $tpl['engage'], crc32($tid));
    $Fs = $F !== '' ? (preg_match('/[.!?]$/', $F) ? $F : $F . '.') : '';
    $ph = $P !== '' && mb_strlen($P) <= 42 ? "$P?" : 'Sound familiar?'; // a long problem does not fit a picture headline
    $v = fn(string $calm, string $bold, string $wild) => ['calm' => $calm, 'bold' => $bold, 'unhinged' => $wild][$vol];
    $join = fn(array $parts) => implode("\n\n", array_values(array_filter(array_map('trim', $parts), fn($x) => $x !== '')));
    $sub = $F !== '' ? pm_lp_line($F, 90) : ($T !== '' ? pm_lp_line($T, 90) : '');
    $bul = array_slice(array_values(array_filter([$Fs !== '' ? pm_lp_line($F, 80) : '', $PR !== '' ? pm_lp_line($PR, 80) : ''])), 0, 3);
    $out = ['headline' => '', 'sub' => $sub, 'caption' => '', 'bullets' => $bul, 'engage' => $tpl['engage']];
    switch ($tid) {
        case 'callout':
            $out['headline'] = $v($ph, $P !== '' && mb_strlen($P) <= 34 ? "$P? Deal with it." : 'Sound familiar?', $P !== '' && mb_strlen($P) <= 34 ? "$P. Again?!" : 'Again? Really?');
            $out['caption'] = $join([$v("$P? If that sounds familiar, you are not alone.", "$P?\n\nIf you just nodded, keep reading.", "Stop scrolling.\n\n$P. Again. You know exactly what we mean."), $eng]);
            break;
        case 'freebie':
            $out['headline'] = $v(ucfirst($T), "$T. Ask for it.", "$T. Yes, really.");
            $out['caption'] = $join([$v("Here is something for you: $T.\n\nTell us a little about what you need and a person will reply.", "Ask for it: $T. You just have to ask.\n\nTell us about your situation and we will take it from there.", "Yes, really: $T.\n\nSkip the long forms. Tell us what you need and a person replies."), $Fs, $eng]);
            break;
        case 'question':
            $out['headline'] = $v('What slows you down most?', 'Be honest: what slows you down?', 'Real talk: your biggest headache?');
            $out['sub'] = 'Tell us in the comments.';
            $out['caption'] = $join([$v('What is the one thing that slows your business down the most?', 'Be honest. What is the one thing that slows your business down the most?', 'Real talk. What is the one headache you would delete from your week if you could?'),
                "For some people it is this: $P.", $eng]);
            break;
        case 'beforeafter':
            $out['headline'] = $v('Before and after', 'The before. The after.', 'Before: ugh. After: ahh.');
            $out['bullets'] = ['Before: ' . pm_lp_line($P, 70), 'After: ' . pm_lp_line(pm_lp_tr($F), 70)];
            $out['caption'] = $join([$v("Before: $P.", "Before: $P.\n\nSound painful? Read on.", "Before: $P.\n\nYes. We have all been there."), 'After: ' . ($F !== '' ? $Fs : '') , $eng]);
            break;
        case 'signs':
            $out['headline'] = $v('Signs you may need a hand', 'Does this sound like you?', 'Check yourself: how many apply?');
            $out['sub'] = 'Be honest with yourself.';
            $out['bullets'] = $signs;
            $out['caption'] = $join([$v('Does this sound like your week?', 'Does this sound like your week? Be honest.', 'Quick self-check. Do not lie to yourself.'), implode("\n", array_map(fn($s) => '- ' . pm_lp_tr($s), $signs)), $Fs, $eng]);
            break;
        case 'tag':
            $out['headline'] = $v('Know someone who needs this?', 'Tag the one who needs this.', 'Tag the friend still doing this.');
            $out['sub'] = $P !== '' ? pm_lp_line($P, 90) : '';
            $out['caption'] = $join([$v('Know someone who deals with this?', 'Tag the person who needs to see this.', 'You just thought of someone. Go on.'), "$P.", $eng]);
            break;
        case 'quote':
            $out['headline'] = $v('Need a quote? Ask us.', $K !== '' ? "Need a quote? Send $K." : 'Need a quote? Message us.', $K !== '' ? "Send $K. That is it." : 'Message us. That is it.');
            $out['caption'] = $join([$v("Need a quote? $T.\n\nSend " . ($K ?: 'us a message') . " on WhatsApp or comment it below, and a person will reply with the next step.", "Ready for a quote? $T.\n\n" . ($K !== '' ? "Send $K on WhatsApp or comment it below." : 'Message us on WhatsApp.') . ' A person replies with the next step.', "A quote, no hoops: $T.\n\n" . ($K !== '' ? "Send $K on WhatsApp or comment it below." : 'Message us on WhatsApp.') . ' A person replies with the next step.')]);
            break;
        case 'proofpost':
            $out['headline'] = $v('A recent result', 'Here is what happened', 'Proof, not promises.');
            $out['sub'] = pm_lp_line($PR, 90);
            $out['bullets'] = array_slice(array_values(array_filter([pm_lp_line($PR, 80), $F !== '' ? pm_lp_line($F, 80) : ''])), 0, 2);
            $out['caption'] = $join(["$PR.", $eng]);
            break;
        case 'howitworks':
            $steps = ['Message us or fill in the short form', 'Tell us a little about what you need', 'A person replies with the next step'];
            $out['headline'] = $v('How it works', 'How it works. Really simple.', 'How it works. No hoops.');
            $out['sub'] = pm_lp_line($T, 90);
            $out['bullets'] = $steps;
            $out['caption'] = $join([$v("How it works: $T.", "How it works, start to finish: $T.", "The whole process, no hoops: $T. Yes, that is all of it."), implode("\n", array_map(fn($s) => '- ' . $s, $steps)), $eng]);
            break;
        case 'ask':
            $out['headline'] = $v('Ask us anything', 'Your questions. Go.', 'Ask us the awkward question.');
            $out['sub'] = pm_lp_line($T, 90);
            $out['caption'] = $join([$v("Got a question? Ask us about: $T.", "Got a question? Ask us about: $T. No question is too small.", "Got an awkward question about this: $T? Good. Ask it."), $eng]);
            break;
        case 'challenge':
            $out['headline'] = $v('A small test this week', 'Try this. Be honest.', 'Dare you: be honest.');
            $out['sub'] = 'Notice how often it happens.';
            $out['caption'] = $join([$v("A small test: notice how often this happens in your business: $P.", "Try this. Notice how often this happens in your business: $P.\n\nBe honest with yourself.", "Dare you. Notice how often this happens in your business: $P.\n\nYes, every time."),
                'If the answer annoys you, you already know what to do next.', $eng]);
            break;
        case 'whatsapp':
            $out['headline'] = $v('Message us on WhatsApp', 'Skip the form. Message us.', 'No forms. No hoops. Just message.');
            $out['caption'] = $join([$v('No form needed.', 'Skip the form.', 'No forms. No hoops.'), "$T.", 'Send ' . ($K ?: 'a message') . ' on WhatsApp and tell us what you need. ' . $v('A person reads it and replies.', 'A person reads it and replies.', 'A real person reads it and replies.')]);
            break;
    }
    $out['headline'] = pm_sx_clip(pm_card_clean_text($out['headline']), 60);
    $out['sub'] = pm_sx_clip(pm_card_clean_text($out['sub']), 100);
    $out['bullets'] = array_values(array_filter(array_map(fn($b) => pm_sx_clip(pm_card_clean_text((string)$b), 80), $out['bullets'])));
    return $out;
}

/* ---------------- making the posts ---------------- */

/** One ready-to-approve post (not saved): the copy, the picture button, the facts, and the offer it belongs to. */
function pm_lp_build_post(array $o, string $tid, string $vol, int $i = 0): array
{
    $c = pm_lp_copy($o, $tid, $vol);
    $brand = (string)$o['brand'];
    $p = [
        'id' => bin2hex(random_bytes(10)), 'brand' => $brand, 'status' => 'draft', 'when' => date('Y-m-d') . ' ' . pm_sx_default_time($brand, $i), '_off' => $i,
        'format' => 'image', 'pillar' => PM_LP_PILLAR, 'cta' => 'offer', 'proof_id' => '', 'caption' => trim($c['caption']), 'hashtags' => [], 'headline' => $c['headline'], 'sub' => $c['sub'],
        'script' => '', 'media' => '', 'fb_id' => '', 'error' => '', 'ig' => '', 'tries' => 0, 'retry_at' => '', 'lint' => [], 'created' => date('Y-m-d H:i'), 'by' => (string)($GLOBALS['PM_WHO'] ?? ''),
        'facts' => pm_lp_facts($o), 'audience' => '', 'hook_pattern' => 'plain', 'offline' => true, 'tokens' => 0,
        'layout' => 'leadad', 'button' => PM_LP_BUTTONS[$o['button']] ?? PM_LP_BUTTONS['whatsapp'], 'bullets' => $c['bullets'],
        'lead_offer' => (string)$o['id'], 'lead_template' => $tid, 'lead_volume' => $vol, 'lead_keyword' => (string)$o['keyword'],
    ];
    if ($o['ends'] !== '') {
        $p['expires'] = $o['ends'];
    }
    $p['alt'] = trim($p['headline'] . ' ' . $p['sub']);
    $p['segment'] = function_exists('pm_sx_segment') ? pm_sx_segment($p, $brand) : '';
    return $p;
}

/**
 * Makes drafts for an offer: one per template (up to 12 a click), spread over free days by the normal cadence, each linted like any post. Posts that
 * break a rule are kept as "needs edit" with the reason, never dropped. Returns ['made','held','ids'].
 */
function pm_lp_make_posts(array $o, array $templates, ?string $vol = null): array
{
    $brand = (string)$o['brand'];
    $vol = isset(PM_LP_VOLUMES[$vol ?? '']) ? (string)$vol : (string)$o['volume'];
    $tids = array_slice(array_values(array_filter(array_unique($templates), fn($t) => pm_lp_can_use($o, (string)$t)[0])), 0, 12);
    if (!$tids) {
        return ['made' => 0, 'held' => 0, 'ids' => []];
    }
    $existing = pm_social_posts();
    $rows = [];
    foreach ($tids as $i => $tid) {
        $rows[] = pm_lp_build_post($o, (string)$tid, $vol, $i);
    }
    $rows = pm_social_cadence($rows, $existing, $brand, date('Y-m-d', strtotime('+1 day')));
    $pool = $existing;
    $held = 0;
    foreach ($rows as $i => $p) {
        $p['hashtags'] = pm_social_hashtags($p, 'facebook');
        $why = pm_social_lint((string)$p['caption'], $brand, 'facebook', pm_social_recent_from($pool, $brand, $p['id']), $p);
        $warn = [];
        foreach (pm_lint_ext($p, 'facebook', $pool) as $r) {
            if ($r['sev'] === 'block') {
                $why[] = $r['msg'];
            } else {
                $warn[] = $r['msg'];
            }
        }
        $p = pm_social_resolve($p, array_values(array_unique($why)));
        $p['warn'] = $warn;
        $held += $p['lint'] ? 1 : 0;
        $rows[$i] = $p;
        $pool[] = $p;
    }
    pm_social_update(function (array $posts) use ($rows) {
        foreach ($rows as $p) {
            $posts[] = $p;
        }
        return $posts;
    });
    return ['made' => count($rows), 'held' => $held, 'ids' => array_column($rows, 'id')];
}

/* ---------------- the button in the picture ---------------- */

/** Picture layout "leadad": a loud headline on the brand colour, up to three lines, and a big button. Registered in PM_CARD_LAYOUTS. */
function pm_card_l_leadad(array &$c, int $top, int $bottom): void
{
    $p = $c['p'];
    $pad = $c['pad'];
    $s = $c['s'];
    pm_card_rrect($c, $pad, $top, $c['W'] - $pad, $bottom, (int)(34 * $s), $c['accent']);
    $in = (int)(50 * $s);
    $w = $c['W'] - 2 * $pad - 2 * $in;
    $on = $c['on'];
    $btnText = $on === [255, 255, 255] ? $c['accentT'] : $c['ink'];
    // the button first: it owns the bottom of the panel
    $label = mb_strtoupper(pm_card_clean((string)($p['button'] ?? 'Send WhatsApp message')));
    $bpx = 32 * $s;
    $maxLabel = $w - (int)(150 * $s);
    while (pm_card_tw($c['f']['sansb'], $bpx, $label) > $maxLabel && $bpx > 16) {
        $bpx -= 2;
    }
    $bh = (int)($bpx * 1.2 + 52 * $s);
    $bw = min($w, pm_card_tw($c['f']['sansb'], $bpx, $label) + (int)(150 * $s));
    $by = $bottom - $in - $bh;
    pm_card_rrect($c, $pad + $in, $by, $pad + $in + $bw, $by + $bh, (int)($bh / 2), $on);
    pm_card_draw($c, [$label], $c['f']['sansb'], $bpx, $btnText, $pad + $in + (int)(42 * $s), (int)($by + ($bh - $bpx * 1.2) / 2), 1.2);
    $ax = $pad + $in + $bw - (int)(54 * $s);
    $ay = (int)($by + $bh / 2);
    $ah = (int)(13 * $s);
    imagefilledpolygon($c['im'], [$ax, $ay - $ah, $ax + (int)(17 * $s), $ay, $ax, $ay + $ah], pm_card_fill($c, $btnText));
    // bullets above it
    $bul = array_slice(array_values(array_filter(array_map(fn($b) => pm_card_clean((string)$b), (array)($p['bullets'] ?? [])))), 0, 3);
    $room = $by - $top - 2 * $in - (int)(28 * $s);
    $bulH = 0;
    $bl = [];
    foreach ($bul as $b) {
        [$px, $lines] = pm_card_fit($b, $c['f']['sans'], (int)(34 * $s), (int)(20 * $s), $w - (int)(54 * $s), (int)(96 * $s), 1.25);
        $h = (int)(count($lines) * $px * 1.25) + (int)(16 * $s);
        if ($bulH + $h > (int)($room * 0.5)) {
            break;
        }
        $bl[] = [$px, $lines, $h];
        $bulH += $h;
    }
    $sub = pm_card_clean((string)($p['sub'] ?? ''));
    $subT = $sub !== '' && !$bl ? $sub : '';
    [$spx, $slines] = $subT !== '' ? pm_card_fit($subT, $c['f']['sans'], (int)(38 * $s), (int)(22 * $s), $w, (int)($room * 0.25), 1.3) : [0, []];
    $subH = $slines ? (int)(count($slines) * $spx * 1.3) + (int)(24 * $s) : 0;
    [$hpx, $hlines] = pm_card_fit(pm_card_headline($p), $c['f']['serifb'], (int)(112 * $s), (int)(36 * $s), $w, max((int)(120 * $s), $room - $bulH - $subH), 1.12);
    $headH = (int)(count($hlines) * $hpx * 1.12);
    $y = pm_card_center($top + $in, $by - (int)(28 * $s), $headH + $subH + $bulH + ($bl ? (int)(18 * $s) : 0));
    $y = pm_card_draw($c, $hlines, $c['f']['serifb'], $hpx, $on, $pad + $in, $y, 1.12);
    if ($slines) {
        $y = pm_card_draw($c, $slines, $c['f']['sans'], $spx, $on, $pad + $in, $y + (int)(18 * $s), 1.3);
    }
    $y += $bl ? (int)(22 * $s) : 0;
    foreach ($bl as [$px, $lines, $h]) {
        $d = (int)(16 * $s);
        imagefilledellipse($c['im'], $pad + $in + (int)($d / 2), $y + (int)($px * 0.62), $d, $d, pm_card_fill($c, $on));
        $y = pm_card_draw($c, $lines, $c['f']['sans'], $px, $on, $pad + $in + (int)(40 * $s), $y, 1.25) + (int)(14 * $s);
    }
}

/* ---------------- how a person responds ---------------- */

/**
 * The call-to-action line for a lead post on a channel: the landing page when the app is hosted, else a tap-to-chat link (where the channel allows a link),
 * else the keyword line. ['line','link'] or null (the caller falls back). Used by sx_variants through pm_ch_cta_line.
 */
function pm_lp_cta_line(array $p, string $ch, bool $allowWa): ?array
{
    $o = pm_lp_get((string)($p['lead_offer'] ?? ''));
    if (!$o || ($o['status'] ?? '') === 'deleted') {
        return null;
    }
    $ref = pm_post_ref($p);
    $url = pm_lp_url((string)$o['brand'], (string)$o['id'], (PM_CH_SRC[$ch] ?? 'x') . '-' . $ref);
    if ($url !== '') {
        return ['line' => (PM_LP_BUTTONS[$o['button']] ?? 'Learn more') . ': ' . $url, 'link' => $url];
    }
    $wa = $allowWa ? pm_lp_wa_link($o, $ref) : '';
    if ($wa !== '') {
        return ['line' => 'Tap to message us on WhatsApp: ' . $wa, 'link' => $wa];
    }
    $n = trim((string)(pm_settings()['phone'] ?? ''));
    $kw = $o['keyword'] !== '' ? 'Send ' . $o['keyword'] . ' on WhatsApp' . ($n !== '' ? ' (' . $n . ')' : '') . ' and a person will reply.' : '';
    return ['line' => $kw, 'link' => ''];
}

/** Active offers with this comment keyword for the business: the offer, or null. A comment counts when it is a short one (five words at most) that starts or ends with the keyword: "CHECK", "check please", "yes CHECK". */
function pm_lp_keyword_hit(string $brand, string $text): ?array
{
    $t = trim($text);
    if ($t === '' || mb_strlen($t) > 40) {
        return null;
    }
    $words = preg_split('/\s+/', trim(strtoupper(preg_replace('/[^A-Za-z0-9 ]+/', ' ', $t))), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach (pm_lp_for_brand($brand, true) as $o) {
        $k = (string)$o['keyword'];
        if ($k !== '' && $words && count($words) <= 5 && ($words[0] === $k || end($words) === $k)) {
            if (($o['ends'] ?? '') === '' || $o['ends'] >= date('Y-m-d')) {
                return $o;
            }
        }
    }
    return null;
}

/** The reply to someone who commented the keyword: the owner's own words when set, else a plain default. Passes through the reply lint like any reply. */
function pm_lp_keyword_reply(array $o, string $from): string
{
    $first = pm_inb_first_name($from);
    $tpl = trim((string)$o['reply']) !== '' ? '{hi}' . trim((string)$o['reply']) . ' {wa}' : '{hi}thanks for sending ' . $o['keyword'] . '. Tell us a little about what you need and a person will reply. {wa}';
    return pm_inb_fill((string)$o['brand'], $tpl, $first);
}

/* ---------------- the public landing page ---------------- */

/* ---------------- results ---------------- */

/** What an offer has done: posts made and published, landing views, leads from its form and from comment keywords. */
function pm_lp_stats(array $o): array
{
    $posts = array_values(array_filter(pm_social_posts(), fn($p) => ($p['lead_offer'] ?? '') === $o['id']));
    $tag = 'offer-' . $o['id'];
    $leads = array_values(array_filter(pm_load('leads', fn() => []), fn($l) => ($l['brand'] ?? '') === $o['brand'] && (str_starts_with((string)($l['src_tag'] ?? ''), $tag)
        || str_starts_with((string)($l['src_tag'] ?? ''), 'offer-' . substr((string)$o['id'], 0, 10)) || in_array((string)($l['source_post'] ?? ''), array_column($posts, 'id'), true))));
    $kw = 0;
    foreach ((array)(pm_load('fb_judged', fn() => [])[$o['brand']] ?? []) as $r) {
        if (str_contains((string)($r['reason'] ?? ''), 'offer keyword (' . $o['keyword'] . ')')) {
            $kw++;
        }
    }
    return ['posts' => count($posts), 'published' => count(array_filter($posts, fn($p) => ($p['status'] ?? '') === 'published')), 'drafts' => count(array_filter($posts, fn($p) => in_array($p['status'] ?? '', ['draft', 'needs_edit', 'approved'], true))),
        'views' => (int)($o['views'] ?? 0), 'leads' => count($leads), 'keyword_comments' => $kw];
}

/* ---------------- handlers ---------------- */

function pm_lp_back(string $msg, string $kind = 'ok'): array
{
    return ['msg' => $msg, 'kind' => $kind, 'to' => 'social&view=leadposts'];
}

/** Create or change an offer. With "make" ticked it also drafts the chosen templates. */
function pm_do_lp_save(string $vb): array
{
    $o = pm_lp_clean($_POST, $vb);
    $id = preg_replace('/[^a-f0-9]/', '', (string)($_POST['id'] ?? ''));
    $old = $id !== '' ? pm_lp_get($id) : null;
    if ($old && ($old['brand'] ?? '') !== $vb) {
        return pm_lp_back('That offer belongs to another business.', 'err');
    }
    if ($bad = pm_lp_check($o)) {
        return pm_lp_back(implode(' ', $bad), 'err');
    }
    if ($o['keyword'] === '') {
        $o['keyword'] = pm_lp_defaults($vb)['keyword'];
    }
    foreach (pm_lp_for_brand($vb, true) as $x) { // one keyword, one meaning: two live offers cannot share it
        if ($x['keyword'] === $o['keyword'] && ($x['id'] ?? '') !== ($old['id'] ?? '')) {
            return pm_lp_back('Another live offer already uses the keyword ' . $o['keyword'] . '. Pick a different one so comments are not mixed up.', 'err');
        }
    }
    if (!$old) {
        $o += ['id' => substr(bin2hex(random_bytes(6)), 0, 10), 'status' => 'active', 'created' => date('Y-m-d H:i'), 'views' => 0];
        pm_lp_update(function (array $rows) use ($o) {
            $rows[] = $o;
            return $rows;
        });
        $id = $o['id'];
    } else {
        pm_lp_update(function (array $rows) use ($id, $o) {
            foreach ($rows as $i => $r) {
                if (($r['id'] ?? '') === $id) {
                    $rows[$i] = $o + $r;
                }
            }
            return $rows;
        });
    }
    $msg = $old ? 'Saved.' : 'Offer saved.';
    $tids = array_values(array_intersect((array)($_POST['templates'] ?? []), array_keys(pm_lp_templates())));
    if (!empty($_POST['make']) && $tids) {
        $r = pm_lp_make_posts(pm_lp_get($id), $tids);
        $msg .= ' ' . $r['made'] . ' draft post' . ($r['made'] === 1 ? '' : 's') . ' made' . ($r['held'] ? ', ' . $r['held'] . ' held for a fix (see the reason on each)' : '') . '. Review and approve them in Social > Plan.';
        return ['msg' => $msg, 'kind' => 'ok', 'to' => 'social'];
    }
    return pm_lp_back($msg);
}

/** Make drafts from an offer's templates. */
function pm_do_lp_post(string $vb): array
{
    $o = pm_lp_get(preg_replace('/[^a-f0-9]/', '', (string)($_POST['id'] ?? '')));
    if (!$o || ($o['brand'] ?? '') !== $vb) {
        return pm_lp_back('Offer not found.', 'err');
    }
    $tids = array_values(array_intersect((array)($_POST['templates'] ?? []), array_keys(pm_lp_templates())));
    if (!$tids) {
        return pm_lp_back('Tick at least one template.', 'err');
    }
    $vol = isset(PM_LP_VOLUMES[$_POST['volume'] ?? '']) ? (string)$_POST['volume'] : null;
    $r = pm_lp_make_posts($o, $tids, $vol);
    if (!$r['made']) {
        return pm_lp_back('None of those templates fit this offer yet: add what they need (shown on each).', 'err');
    }
    return ['msg' => $r['made'] . ' draft post' . ($r['made'] === 1 ? '' : 's') . ' made' . ($r['held'] ? ', ' . $r['held'] . ' held for a fix (see the reason on each)' : '') . '. Nothing is published until you approve it. Review them in Plan.', 'kind' => 'ok', 'to' => 'social'];
}

function pm_lp_patch(string $vb, string $id, callable $fn): bool
{
    $ok = false;
    pm_lp_update(function (array $rows) use ($vb, $id, $fn, &$ok) {
        foreach ($rows as $i => $r) {
            if (($r['id'] ?? '') === $id && ($r['brand'] ?? '') === $vb) {
                $rows[$i] = $fn($r);
                $ok = true;
            }
        }
        return $rows;
    });
    return $ok;
}

function pm_do_lp_status(string $vb): array
{
    $id = preg_replace('/[^a-f0-9]/', '', (string)($_POST['id'] ?? ''));
    $to = ($_POST['to'] ?? '') === 'paused' ? 'paused' : 'active';
    if ($to === 'active') {
        $o = pm_lp_get($id);
        foreach (pm_lp_for_brand($vb, true) as $x) {
            if ($o && $x['keyword'] === $o['keyword'] && $x['id'] !== $id) {
                return pm_lp_back('Another live offer uses the keyword ' . $o['keyword'] . '. Change one of them first.', 'err');
            }
        }
    }
    return pm_lp_patch($vb, $id, function (array $r) use ($to) {
        $r['status'] = $to;
        return $r;
    }) ? pm_lp_back($to === 'paused' ? 'Paused: its page closes and its keyword stops being picked up.' : 'Live again.') : pm_lp_back('Offer not found.', 'err');
}

function pm_do_lp_delete(string $vb): array
{
    $id = preg_replace('/[^a-f0-9]/', '', (string)($_POST['id'] ?? ''));
    $found = false;
    pm_lp_update(function (array $rows) use ($vb, $id, &$found) {
        return array_values(array_filter($rows, function ($r) use ($vb, $id, &$found) {
            $hit = ($r['id'] ?? '') === $id && ($r['brand'] ?? '') === $vb;
            $found = $found || $hit;
            return !$hit;
        }));
    });
    return $found ? pm_lp_back('Deleted. Posts already made keep their text; their button falls back to WhatsApp.') : pm_lp_back('Offer not found.', 'err');
}
