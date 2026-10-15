<?php
/**
 * The first email to a Travel Malawi lead: a short personal opening written for that place (the only part the AI writes), then a standard part that is the same
 * for every stay and comes from the owner's own words in Sales > Targeting and messages > "Default first message" (its {opening} line is the only part the AI writes): what the platform is, what a host gets, the set-up steps with
 * the link, that it is free for now and why. Nothing in the standard part is invented: it is what the owner typed (the starting text uses only facts the app
 * already holds), and the whole email must pass the same wording rules as any first email (one plain link, no prices, no spam words, at most 190 words).
 */

const PM_TM_LINK = 'https://ulendomalawi.com';

/** Our aim, the hook of every first message: Malawi's own directory of stays, built with the hosts, free, where the big booking sites take a cut. */
const PM_TM_AIM = "We are building Malawi's own directory of independent stays, together with the hosts who run them. Joining is free and there is no commission, unlike Booking.com and the other big sites that take a cut of every stay.";

/** The WhatsApp version of the hook, after "Hello {first_name}, this is {company}." */
const PM_TM_WA_AIM = "We are building Malawi's own directory of independent stays together with hosts, and we would love {name} in it. It is free, with no commission, unlike Booking.com. Guests chat and video call you before they come, and your reviews from different sources are shown together.";

/** The second line of every first email: who is writing, and that we have not met (the name is in the signature). */
const PM_TM_WHO = "I'm with {company}; we haven't met yet, so I'll keep this brief.";

/** The paragraph after the personal opening: the aim, then what a host gets, in the host's own money. */
const PM_TM_AIM_EMAIL = "We are building Malawi's own directory of independent stays, together with the hosts who run them. Guests book you directly and pay you in {currency}, with no commission, unlike Booking.com and the other big sites that take a cut of every stay. They can chat and video call you before they come.";

/** The invitation that ends every first email: the one link in the text (the share kit adds the direct links below it). */
const PM_TM_INVITE = "Please visit {link} to list {name} for free and manage your page and your online presence yourself, or simply reply and our team will set it up for you. There is no catch: we are building Malawi's tourism together.";

/** The benefit line about reviews: open about what guests said, wherever they said it, is what earns a new guest's trust. */
const PM_TM_REVIEWS = 'Your reviews from different sources, shown together, so guests trust you.';

/** Lines of the earlier wording and what replaced them (the hook first, then a shorter list so a written opening still fits in 190 words). Applied once to saved text and unsent drafts. */
const PM_TM_SWAP = [
    'Travel Malawi is a direct booking platform for independent lodges, B&Bs, cottages, guest houses and safari camps across Malawi.' => PM_TM_AIM,
    'Guests chat and video call with you in the app, so they see your place before booking.' => 'Guests chat and video call with you before booking, so they see your place.',
    'Your listing shows your reviews from different sources together, so guests see the honest picture and trust you.' => PM_TM_REVIEWS,
    'Ulendo, our AI concierge, works with you and your manager: it writes your listing and helps answer guests.' => 'Ulendo, our AI concierge, helps you write your listing and answer guests.',
    'or reply and our concierge team sets your listing up for you.' => 'or reply and our concierge team sets it up for you.',
    'We review it before it goes live.' => 'We review it, and it goes live.',
    'Add your rooms and rates in your host dashboard, and booking requests arrive there.' => 'Booking requests arrive in your host dashboard.',
    'There is no commission, no listing fee and no monthly charge for now.' => 'There is no listing fee or monthly charge for now.',
    'We help independent stays like {name} take direct bookings with no commission. Guests chat and video call with you before they come, and your reviews from different sources are shown together, so they trust you. Listing is free for now.'
        => PM_TM_WA_AIM,
];

/** The starting text of the default message (and what is shown until the owner saves their own): the facts already in Travel Malawi's brain, in the order a host cares about them. */
function pm_tm_email_defaults(): array
{
    return [
        'intro' => PM_TM_AIM,
        'benefits_title' => 'What you get:',
        'benefits' => ['Guests pay you directly, in {currency}: mobile money, bank or cash.',
            'Guests chat and video call with you before booking, so they see your place.', // where we win: the guest meets the host before they come
            PM_TM_REVIEWS,
            'Ulendo, our AI concierge, helps you write your listing and answer guests.',
            'There is a host starter guide to help you get set up.'],
        'steps_title' => 'How to start:',
        'steps' => ["Send your property's details at {link}, or reply and our concierge team sets it up for you.", 'We review it, and it goes live.',
            'Booking requests arrive in your host dashboard.'],
        'link' => PM_TM_LINK,
        'free' => 'There is no listing fee or monthly charge for now.',
        'why' => '', // kept short: the first email must stay under 190 words
        'closing' => 'Shall I send you the host starter guide?',
    ];
}

/** The starting text's two lines about the starter guide as something we could send; once a pack is ready in the share kit they say it is already on its way. */
const PM_TM_LEGACY = ['benefit' => 'There is a host starter guide to help you get set up.', 'closing' => 'Shall I send you the host starter guide?'];

/** What those lines say when a host starter pack is ready (attached to the email, or opened from a link): ['benefit', 'closing', 'old'] (the benefit lines it replaces), or [] when there is none. */
function pm_tm_pack_lines(): array
{
    $st = function_exists('pm_share_pack_state') ? pm_share_pack_state('travel') : 'none';
    $b = fn(string $how) => 'There is a host starter pack to help you get set up, and ' . $how;
    return $st === 'none' ? [] : ['benefit' => $b($st === 'attached' ? 'it is attached to this email.' : 'the link is below.'), 'closing' => pm_our_wa_number(pm_settings()) !== '' ? 'Reply here, or on WhatsApp, and I will help you get listed.' : 'Reply here and I will help you get listed.',
        'old' => [PM_TM_LEGACY['benefit'], $b('it is attached to this email.'), $b('the link is below.')]];
}

/** The same lines with the "shall I send you" wording (or an earlier ready-pack wording) swapped for the one that is true now; any line the owner wrote themselves is left alone. */
function pm_tm_pack_swap(string $text): string
{
    $p = pm_tm_pack_lines();
    return $p ? str_replace(array_merge($p['old'], [PM_TM_LEGACY['closing']]), array_merge(array_fill(0, count($p['old']), $p['benefit']), [$p['closing']]), $text) : $text;
}

/** The standard part as saved in Settings; a field that was never saved uses the starting text, a field saved empty stays empty. */
function pm_tm_email_cfg(): array
{
    $raw = pm_load('settings', 'pm_default_settings');
    $stored = (array)(((array)($raw['travel'] ?? []))['host_email'] ?? []);
    $c = array_replace(pm_tm_email_defaults(), $stored);
    foreach (['benefits', 'steps'] as $k) {
        $c[$k] = array_values(array_filter(array_map('strval', (array)$c[$k]), fn($x) => trim($x) !== ''));
    }
    if (trim((string)$c['link']) === '') {
        $c['link'] = PM_TM_LINK;
    }
    return $c;
}

function pm_tm_first_name(array $lead): string
{
    $f = trim(explode(' ', trim((string)($lead['contact'] ?? '')))[0] ?? '');
    return (string)preg_replace('/[^\p{L}\' -]/u', '', $f);
}

/** An opening that is always true: why we are writing to this place. No name (the signature carries it) and no pretending to be a traveller: we are offering a service. Used when the AI gives none (or a bad one). */
function pm_tm_default_opening(array $lead): string
{
    $city = trim((string)($lead['city'] ?? ''));
    return 'I would love ' . trim((string)($lead['name'] ?? 'your place')) . ($city !== '' && strcasecmp($city, 'Malawi') !== 0 ? " in $city" : '') . ' to be part of something new.';
}

/** What the personal opening must do (the study and the writer both follow it): show the owner we really looked at their place. */
const PM_TM_OPENING_RULE = "two short sentences, at most 40 words, that show we really researched their place: ONE true, specific observation from the research or evidence (their website, how guests book or reach them, their rooms or setting, a recent review or news), then, only conditionally ('when ..., ...'), what it means for travellers trying to book them, or what it could win them. Friendly and helpful, never critical.";

/** A sentence the Travel Malawi opening must not have: the writer naming themselves (the signature does), or posing as a traveller looking for a stay. */
const PM_TM_OPENING_BANNED = '/\b(I\'?m|I am|my name is|this is|it\'?s)\s+[A-Z][\p{L}\'-]+\s+(from|with|at|of)\b|\bmy name\b|\b(looking|searching|browsing) (for|at|up|around|through)\b[^.?]{0,60}\b(stays?|accommodation|rooms?|lodges?|places?|somewhere|options)\b|\b(planning|plan) (a |my |our )?(trip|stay|visit|holiday)|\b(I|we) (was|were|am|are) (travell?ing|visiting|staying)|\bplaces? to stay\b/iu';

/** The AI's opening made safe: no greeting (the app adds one), no self-introduction or traveller pose, no link, number, price or exclamation mark, at most two sentences and 45 words. '' when unusable. */
function pm_tm_clean_opening(string $s): string
{
    $s = trim((string)preg_replace('/\s+/u', ' ', strip_tags($s)));
    $s = (string)preg_replace('/^(hello|hi|dear|good (morning|day|afternoon))\b[^,.!?]{0,40}[,.!?]\s*/iu', '', $s);
    if ($s === '' || preg_match('#https?://|www\.|\d|[$£€]|\bMWK\b|\bUSD\b#iu', $s)) {
        return '';
    }
    $s = str_replace('!', '.', $s);
    $keep = [];
    foreach ((array)preg_split('/(?<=[.?])\s+/u', $s) as $x) {
        $cut = (string)preg_replace('/,?\s+while (looking|searching|browsing)\b[^.?]*/iu', '', (string)$x); // "I came across X while looking at stays" keeps "I came across X", when that still says something
        if (($cut === $x || str_word_count($cut) >= 7) && !preg_match(PM_TM_OPENING_BANNED, $cut)) {
            $keep[] = $cut;
        }
    }
    $s = implode(' ', array_slice($keep, 0, 2));
    if ($s === '') {
        return '';
    }
    return count((array)preg_split('/\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY)) > 45 ? '' : $s; // the observation and what it means for them
}

/** The first email to a stay: the Travel Malawi default message (Sales > Targeting and messages) with the line the study or the AI wrote in place of {opening}. $template builds its starting text instead, with {placeholders} left in. */
function pm_tm_email_compose(array $lead, string $opening = '', bool $template = false): string
{
    if (!$template) {
        return pm_dm_render($lead, $opening)['email_body'];
    }
    return implode("\n\n", ['Good day {first_name},', PM_TM_WHO, '{opening}', PM_TM_AIM_EMAIL, PM_TM_INVITE]); // short and human: who we are, what we found about them, the aim, the invitation
}

/** WRITER for Travel Malawi: the AI writes the subject, the personal opening and the WhatsApp message; the email is the opening plus the standard part. */
function pm_tm_write(array $leads): array
{
    $all = $leads;
    $ready = []; // the day's study already wrote the personal line and a subject: the email is that line plus the standard part, and the AI is not asked again
    foreach ($leads as $l) {
        if (($p = pm_lead_personal($l)) !== '' && empty($l['rewrite_reason'])) {
            $dm = pm_dm_render($l, $p);
            $subj = trim((string)($l['research']['personal_subject'] ?? ''));
            $ready[] = ['id' => $l['id'], 'email_subject' => $subj !== '' ? $subj : $dm['email_subject'], 'email_body' => $dm['email_body'], 'whatsapp' => $dm['whatsapp'], 'starter' => true];
        }
    }
    $done = array_column($ready, 'id');
    $leads = array_values(array_filter($leads, fn($l) => !in_array($l['id'], $done, true)));
    if (!$leads) {
        return pm_subjects_polish($ready, $all);
    }
    return array_merge(pm_subjects_polish($ready, $all), pm_tm_write_ai($leads));
}

/** The AI part of the Travel Malawi writer, for leads the study wrote no personal line for. */
function pm_tm_write_ai(array $leads): array
{
    $s = pm_settings();
    $subjectLine = function_exists('pm_email_exp_example') ? pm_email_exp_example('travel') : '';
    $system = pm_agents_company_brief('tiny') . "\nYou are writing outreach for {$s['company_name']}, a business inviting places to stay to list with it. For each item write three things. "
        . 'email_subject: under 45 characters, specific to them, truthful, in the subject_style given, written like a normal subject line: a capital letter first and a capital letter on every town, area, road, person and business name (for example "Rooms near the Shire River barrage?"), every other word in ordinary lowercase, no full stop, no exclamation marks, no word in ALL CAPS except an acronym such as VAT. '
        . 'Our aim, which every message must make a host excited about: ' . PM_TM_AIM . ' Hosts are partners building it with us, not customers. '
        . 'opening: ' . PM_TM_OPENING_RULE . ' '
        . 'The paragraph before it says who we are and that we have not met; the paragraph after it is the aim above; never repeat either. '
        . 'Never give your own name or introduce yourself (the signature does that). We are a business offering a service: never say or suggest that you were looking for a place to stay, planning a trip, travelling or a possible guest. '
        . 'No greeting, no sign-off, no promises, no numbers, no prices, no link, no exclamation marks. '
        . (pm_wa_on() ? 'whatsapp: 35 to 60 words, one paragraph: a greeting with the first name if given, that this is ' . $s['company_name'] . ' (never your own name: the sign-off carries it), then straight away the aim (we are building Malawi\'s own directory of stays together with hosts and would love them in it; free, no commission, unlike Booking.com), one gain (guests chat and video call them before they come, or their reviews from different sources shown together), one easy question. Never pose as a traveller. ' : 'No WhatsApp message: this business works by email only. ')
        . 'email_subject: make an owner curious to open it, for example about joining Malawi\'s own directory of stays, within the subject_style. '
        . 'The rest of the email (what the platform is, the benefits, the set-up steps, the link and that it is free) is a fixed text the app adds, so never describe it. '
        . ($subjectLine !== '' ? $subjectLine . ' ' : '') . pm_market_pulse_line() . ' ' . pm_tb_line('travel', 'writers') . ' Where an item has owner_feedback, the owner turned the last version down for that reason: fix exactly that. ' . PM_TRUST_RULES . ' ' . PM_HUMAN_RULES . ' ' . PM_AGENT_RULES;
    $slim = array_map(fn($l) => ['id' => $l['id'], 'name' => $l['name'], 'type' => $l['type'] ?? '', 'city' => $l['city'] ?? '', 'contact' => $l['contact'] ?? '', 'evidence' => array_slice((array)($l['evidence'] ?? []), 0, 2),
        'online_gaps' => array_slice((array)($l['social_gaps'] ?? []), 0, 2), 'research' => function_exists('pm_research_brief') ? pm_research_brief($l) : '',
        'subject_style' => function_exists('pm_subject_style') ? pm_subject_style(pm_email_exp_arm((string)$l['id'], 'travel')) : ''] + (!empty($l['rewrite_reason']) ? ['owner_feedback' => (string)$l['rewrite_reason']] : []), $leads);
    $out = pm_agent_list(pm_agent_json(pm_claude($system, json_encode($slim, JSON_UNESCAPED_UNICODE) . "\nJSON array of {\"id\",\"email_subject\",\"opening\"" . (pm_wa_on() ? ",\"whatsapp\"" : '') . "}. Never greet with the business name.", false, 2000, 'write')));
    $by = [];
    foreach ($out as $x) {
        if (is_array($x) && isset($x['id']) && is_scalar($x['id'])) {
            $by[(string)$x['id']] = $x;
        }
    }
    $txt = fn($v, string $ch = 'email') => is_scalar($v) ? pm_human_polish(trim(strip_tags((string)$v)), $ch) : '';
    $res = [];
    foreach ($leads as $l) {
        $x = $by[(string)$l['id']] ?? [];
        $subject = $txt($x['email_subject'] ?? '');
        if ($subject === '' || mb_strlen($subject) > 70 || str_contains($subject, '!') || preg_match('/^\s*(re|fwd?):/i', $subject)) {
            $subject = 'Direct bookings for ' . mb_substr(trim((string)$l['name']), 0, 40);
        }
        $first = pm_tm_first_name($l);
        $wa = $txt($x['whatsapp'] ?? '', 'whatsapp');
        if ($wa === '') {
            $wa = 'Hello' . ($first !== '' ? " $first" : '') . ', this is Travel Malawi. ' . str_replace('{name}', trim((string)$l['name']) ?: 'your place', PM_TM_WA_AIM) . ' May I send you the details?';
        }
        $res[] = ['id' => $l['id'], 'email_subject' => $subject, 'email_body' => pm_tm_email_compose($l, pm_tm_clean_opening($txt($x['opening'] ?? ''))), 'whatsapp' => $wa];
    }
    return pm_subjects_polish($res, $leads);
}

/** Ids of Travel Malawi leads whose unsent draft is still in the old format (no link) and that nobody has edited by hand or approved. */
function pm_tm_rewrite_candidates(array $leads): array
{
    $link = (string)pm_tm_email_cfg()['link'];
    $out = [];
    foreach ($leads as $id => $l) {
        $d = (array)($l['drafts'] ?? []);
        if (($l['brand'] ?? 'promanaged') !== 'travel' || trim((string)($d['email_body'] ?? '')) === '' || !empty($l['sent']) || !empty($l['approved_at']) || !empty($d['edited_at'])
            || !in_array($l['status'] ?? '', ['new', 'qualified', 'drafted'], true) || str_contains((string)$d['email_body'], $link)) {
            continue;
        }
        $out[] = (string)$id;
    }
    return $out;
}

/** Unsent Travel Malawi drafts the rewrite will not touch, and why: ['approved' => n, 'edited' => n]. */
function pm_tm_rewrite_kept(array $leads): array
{
    $link = (string)pm_tm_email_cfg()['link'];
    $k = ['approved' => 0, 'edited' => 0];
    foreach ($leads as $l) {
        $d = (array)($l['drafts'] ?? []);
        if (($l['brand'] ?? 'promanaged') !== 'travel' || trim((string)($d['email_body'] ?? '')) === '' || !empty($l['sent']) || str_contains((string)$d['email_body'], $link)) {
            continue;
        }
        $k['approved'] += !empty($l['approved_at']) ? 1 : 0;
        $k['edited'] += empty($l['approved_at']) && !empty($d['edited_at']) ? 1 : 0;
    }
    return $k;
}

/** Redrafts those emails in the new format, six leads to a call. A draft that was sent, approved or edited while this ran is left alone. ['rewritten','failed']. */
function pm_tm_rewrite_drafts(): array
{
    $done = 0;
    $failed = 0;
    foreach (array_chunk(pm_tm_rewrite_candidates(pm_leads()), 6) as $chunk) {
        $all = pm_leads();
        $batch = [];
        foreach ($chunk as $id) {
            if (isset($all[$id])) {
                $batch[] = ['id' => $id] + $all[$id];
            }
        }
        try {
            $w = pm_tm_write($batch);
        } catch (Throwable) {
            $failed += count($batch);
            continue;
        }
        pm_update('leads', function (array $cur) use ($w, &$done) {
            foreach ($w as $r) {
                $id = (string)$r['id'];
                $d = (array)($cur[$id]['drafts'] ?? []);
                if (!isset($cur[$id]) || !empty($cur[$id]['sent']) || !empty($cur[$id]['approved_at']) || !empty($d['edited_at'])) {
                    continue;
                }
                $cur[$id]['drafts'] = ['email_subject' => (string)$r['email_subject'], 'email_body' => (string)$r['email_body'], 'whatsapp' => (string)$r['whatsapp']];
                pm_lead_note($cur[$id], 'Email redrafted in the new Travel Malawi format');
                $done++;
            }
            return $cur;
        }, fn() => []);
    }
    return ['rewritten' => $done, 'failed' => $failed];
}

/** Unsent first messages written before the rule: the opening that named the sender or posed as a traveller is rewritten, the WhatsApp says "this is Travel Malawi", and the reviews line joins the benefits. Returns how many changed. */
function pm_tm_fix_drafts(): int
{
    $chat = '- Guests chat and video call with you in the app, so they see your place before booking.';
    $n = 0;
    pm_update('leads', function (array $cur) use (&$n, $chat) {
        foreach ($cur as $id => $l) {
            $d = (array)($l['drafts'] ?? []);
            if (($l['brand'] ?? 'promanaged') !== 'travel' || !empty($l['sent']) || !$d) {
                continue;
            }
            $paras = explode("\n\n", str_replace("\r", '', (string)($d['email_body'] ?? '')));
            if (isset($paras[1]) && preg_match(PM_TM_OPENING_BANNED, $paras[1])) {
                $paras[1] = pm_tm_clean_opening($paras[1]) ?: pm_tm_default_opening($l);
            }
            $body = implode("\n\n", $paras);
            if (!preg_match('/reviews? from different/i', $body)) {
                $body = str_replace("$chat\n", "$chat\n- " . PM_TM_REVIEWS . "\n", $body);
            }
            $wa = (string)preg_replace('/\b(I\'?m|I am|this is|it\'?s)\s+[A-Z][\p{L}\'-]+\s+(from|with|at)\s+(Travel Malawi)\b/u', 'this is $3', (string)($d['whatsapp'] ?? ''));
            if ($body !== (string)($d['email_body'] ?? '') || $wa !== (string)($d['whatsapp'] ?? '')) {
                $cur[$id]['drafts']['email_body'] = $body;
                $cur[$id]['drafts']['whatsapp'] = $wa;
                pm_lead_note($cur[$id], 'Draft updated: no sender name in the opening, and the reviews line added');
                $n++;
            }
        }
        return $cur;
    }, fn() => []);
    return $n;
}

/** Runs pm_tm_fix_drafts once per data folder (the first page opened after the update), so a server copy is fixed without anyone pressing anything. */
function pm_tm_fix_once(): void
{
    $done = pm_load('migrations', fn() => []);
    if (empty($done['tm_opening_v1'])) {
        pm_tm_fix_v1();
    }
    if (empty($done['tm_aim_v2'])) {
        $n = pm_tm_aim_v2();
        pm_update('migrations', fn(array $m) => $m + ['tm_aim_v2' => date('c') . " ($n drafts)"], fn() => []);
    }
    if (empty($done['tm_short_v3'])) {
        $n = pm_tm_short_v3();
        pm_update('migrations', fn(array $m) => $m + ['tm_short_v3' => date('c') . " ($n drafts)"], fn() => []);
    }
}

/**
 * The short, human first email replaces the long list: the saved default message still in the list format takes the new starting text, and unsent drafts nobody
 * approved or edited are drafted again from it, keeping the personal opening each one already had. Returns how many drafts changed.
 */
function pm_tm_short_v3(): int
{
    $old = fn(string $b) => str_contains($b, 'What you get:') || str_contains($b, 'How to start:');
    pm_update('message_defaults', function (array $all) use ($old) {
        if (isset($all['travel']['email_body']) && $old((string)$all['travel']['email_body'])) {
            unset($all['travel']['email_body']); // never saved = the starting text
        }
        return $all;
    }, fn() => []);
    pm_update('settings', function (array $s) {
        unset($s['travel']['host_email']); // the list it held is gone from the email
        return $s;
    }, 'pm_default_settings');
    $aim = mb_substr(PM_TM_AIM, 0, 40);
    $n = 0;
    pm_update('leads', function (array $cur) use (&$n, $old, $aim) {
        foreach ($cur as $id => $l) {
            $d = (array)($l['drafts'] ?? []);
            $body = str_replace("\r", '', (string)($d['email_body'] ?? ''));
            if (($l['brand'] ?? 'promanaged') !== 'travel' || !$old($body) || !empty($l['sent']) || !empty($l['approved_at']) || !empty($d['edited_at'])) {
                continue;
            }
            $paras = explode("\n\n", $body);
            $at = 0;
            foreach ($paras as $i => $p) {
                if (str_starts_with(trim($p), $aim)) {
                    $at = $i;
                    break;
                }
            }
            $open = $at >= 2 ? trim($paras[$at - 1]) : '';
            $cut = trim((string)preg_replace('/,?\s+and (we|I)(\'d| would) love\b[^.]*\./iu', '.', $open)); // the next paragraph already says what we are building
            $open = str_word_count($cut) >= 7 ? $cut : $open;
            $cur[$id]['drafts']['email_body'] = pm_dm_as('travel', fn() => pm_dm_render(['id' => $id] + $l, $open)['email_body']);
            pm_lead_note($cur[$id], 'Draft updated: the shorter, more personal first email');
            $n++;
        }
        return $cur;
    }, fn() => []);
    return $n;
}

/** Earlier wording swapped for the hook (PM_TM_SWAP), and the earlier fallback opening for the new one. */
function pm_tm_swap_text(string $t): string
{
    $t = strtr(str_replace("\r", '', $t), PM_TM_SWAP);
    $t = (string)preg_replace('/^I am with Travel Malawi\.\s+/mu', '', $t); // the next paragraph says who we are
    return (string)preg_replace('/^I am writing to invite (.{2,120}?) to take bookings directly from travellers\.$/mu', 'I would love $1 to be part of something new.', $t);
}

/** The hook reaches what was saved before it: the default message, the starting text, the brain's "about" (when never changed by hand), and unsent Travel Malawi drafts. Returns how many drafts changed. */
function pm_tm_aim_v2(): int
{
    pm_update('message_defaults', function (array $all) {
        foreach (['email_body', 'whatsapp'] as $k) {
            if (isset($all['travel'][$k])) {
                $all['travel'][$k] = pm_tm_swap_text((string)$all['travel'][$k]);
            }
        }
        return $all;
    }, fn() => []);
    pm_update('settings', function (array $s) {
        if (isset($s['travel']['host_email']) && is_array($s['travel']['host_email'])) {
            array_walk_recursive($s['travel']['host_email'], function (&$v) {
                $v = is_string($v) ? pm_tm_swap_text($v) : $v;
            });
        }
        $br = (array)($s['travel']['brain'] ?? []);
        if (trim((string)($br['about'] ?? '')) === 'A direct booking platform connecting travellers with independent lodges, B&Bs, cottages, guest houses and safari camps across Malawi (stays only).') {
            $s['travel']['brain']['about'] = pm_brain_defaults('travel')['about'];
        }
        if (trim((string)($br['facts'] ?? '')) !== '' && !preg_match('/different sources/i', (string)$br['facts'])) {
            $s['travel']['brain']['facts'] = rtrim((string)$br['facts']) . "\nA listing shows the stay's reviews from different sources side by side, not only the platform's own; that openness is why travellers trust the place and book";
        }
        return $s;
    }, 'pm_default_settings');
    $n = 0;
    pm_update('leads', function (array $cur) use (&$n) {
        foreach ($cur as $id => $l) {
            $d = (array)($l['drafts'] ?? []);
            if (($l['brand'] ?? 'promanaged') !== 'travel' || !empty($l['sent']) || !$d) {
                continue;
            }
            $b = pm_tm_swap_text((string)($d['email_body'] ?? ''));
            $w = pm_tm_swap_text((string)($d['whatsapp'] ?? ''));
            if ($b !== (string)($d['email_body'] ?? '') || $w !== (string)($d['whatsapp'] ?? '')) {
                $cur[$id]['drafts']['email_body'] = $b;
                $cur[$id]['drafts']['whatsapp'] = $w;
                pm_lead_note($cur[$id], 'Draft updated: it now opens with the directory we are building together');
                $n++;
            }
        }
        return $cur;
    }, fn() => []);
    return $n;
}

/** The first fix: no sender name or traveller pose in openings, and the reviews line. */
function pm_tm_fix_v1(): void
{
    pm_update('message_defaults', function (array $all) { // the saved default message: the reviews line, and a WhatsApp that names the business, not the sender
        if (empty($all['travel'])) {
            return $all;
        }
        $b = (string)($all['travel']['email_body'] ?? '');
        if ($b !== '' && !preg_match('/reviews? from different/i', $b)) {
            $chat = '- Guests chat and video call with you in the app, so they see your place before booking.';
            $all['travel']['email_body'] = str_replace("$chat\n", "$chat\n- " . PM_TM_REVIEWS . "\n", str_replace("\r", '', $b));
        }
        $w = str_replace('{first_name}, {intro}.', '{first_name}, this is {company}.', (string)($all['travel']['whatsapp'] ?? ''));
        $all['travel']['whatsapp'] = str_replace(', and Ulendo, our AI concierge, helps you and your manager answer them.', ', and your reviews from different sources are shown together, so they trust you.', $w);
        return $all;
    }, fn() => []);
    pm_update('migrations', fn(array $m) => $m + ['tm_opening_v1' => date('c') . ' (' . pm_tm_fix_drafts() . ' drafts)'], fn() => []);
}

/** When a host starter pack becomes ready, unsent first emails that still ask "shall I send you the guide?" say it is attached or linked instead. Approved and sent emails are not touched. Returns how many changed. */
function pm_tm_pack_refresh_drafts(): int
{
    if (!pm_tm_pack_lines()) {
        return 0;
    }
    $n = 0;
    pm_update('leads', function (array $cur) use (&$n) {
        foreach ($cur as $id => $l) {
            $d = (array)($l['drafts'] ?? []);
            $body = (string)($d['email_body'] ?? '');
            if (($l['brand'] ?? 'promanaged') !== 'travel' || $body === '' || !empty($l['sent']) || !empty($l['approved_at']) || !in_array($l['status'] ?? '', ['new', 'qualified', 'drafted'], true)) {
                continue;
            }
            $new = pm_tm_pack_swap($body);
            if ($new !== $body) {
                $cur[$id]['drafts']['email_body'] = $new;
                pm_lead_note($cur[$id], 'Email updated: the host starter pack is ready');
                $n++;
            }
        }
        return $cur;
    }, fn() => []);
    return $n;
}
