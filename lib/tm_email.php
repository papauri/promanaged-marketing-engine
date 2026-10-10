<?php
/**
 * The first email to a Travel Malawi lead: a short personal opening written for that place (the only part the AI writes), then a standard part that is the same
 * for every stay and comes from the owner's own words in Settings > Travel Malawi > "First email": what the platform is, what a host gets, the set-up steps with
 * the link, that it is free for now and why. Nothing in the standard part is invented: it is what the owner typed (the starting text uses only facts the app
 * already holds), and the whole email must pass the same wording rules as any first email (one plain link, no prices, no spam words, at most 190 words).
 */

const PM_TM_LINK = 'https://ulendomalawi.com';

/** The starting text of the standard part: the facts already in Travel Malawi's brain, in the order a host cares about them. */
function pm_tm_email_defaults(): array
{
    return [
        'intro' => 'Travel Malawi is a direct booking platform for independent lodges, B&Bs, cottages, guest houses and safari camps across Malawi.',
        'benefits_title' => 'What you get:',
        'benefits' => ['Guests book and pay you directly, in kwacha or dollars.', 'You control your own rooms, rates and blocked dates.', 'There is a host starter guide to help you get set up.'],
        'steps_title' => 'How to start:',
        'steps' => ["Go to {link} and send your property's details (name, town, contact).", 'We review your listing before it goes live.',
            'Add your rooms, rates and blocked dates in your host dashboard. Booking requests then arrive there, and confirmations go out over WhatsApp.'],
        'link' => PM_TM_LINK,
        'free' => 'There is no commission, no listing fee and no monthly charge for now.',
        'why' => 'We are building the platform together with our first hosts, so we are not charging while we do.',
        'closing' => 'Shall I send you the host starter guide?',
    ];
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

/** An opening that is always true: who is writing, and how we came across the place. Used when the AI gives none (or a bad one). */
function pm_tm_default_opening(array $lead): string
{
    $sender = trim(explode(' ', pm_sender_name())[0] ?? '');
    $co = (string)(pm_settings()['company_name'] ?? 'Travel Malawi');
    $city = trim((string)($lead['city'] ?? ''));
    return ($sender !== '' ? "I'm $sender from $co" : "I am writing from $co") . ', and I came across ' . trim((string)($lead['name'] ?? 'your place')) . ' while looking at places to stay in '
        . ($city !== '' && strcasecmp($city, 'Malawi') !== 0 ? $city : 'Malawi') . '.';
}

/** The AI's opening made safe: no greeting (the app adds one), no link, number, price or exclamation mark, at most two sentences and 40 words. '' when unusable. */
function pm_tm_clean_opening(string $s): string
{
    $s = trim((string)preg_replace('/\s+/u', ' ', strip_tags($s)));
    $s = (string)preg_replace('/^(hello|hi|dear|good (morning|day|afternoon))\b[^,.!?]{0,40}[,.!?]\s*/iu', '', $s);
    if ($s === '' || preg_match('#https?://|www\.|\d|[$£€]|\bMWK\b|\bUSD\b#iu', $s)) {
        return '';
    }
    $s = str_replace('!', '.', $s);
    $s = implode(' ', array_slice((array)preg_split('/(?<=[.?])\s+/u', $s), 0, 2));
    return count((array)preg_split('/\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY)) > 40 ? '' : $s;
}

/** The whole first email body (no sign-off: the app adds that): greeting, opening, then the standard part. */
function pm_tm_email_compose(array $lead, string $opening = ''): string
{
    $c = pm_tm_email_cfg();
    $first = pm_tm_first_name($lead);
    $open = trim($opening) !== '' ? trim($opening) : pm_tm_default_opening($lead);
    $list = fn(array $rows, bool $numbered) => implode("\n", array_map(fn($i, $x) => ($numbered ? ($i + 1) . '. ' : '- ') . str_replace('{link}', (string)$c['link'], $x), array_keys($rows), $rows));
    $parts = [$first !== '' ? "Hello $first," : 'Hello,', $open, trim((string)$c['intro'])];
    if ($c['benefits']) {
        $parts[] = trim((string)$c['benefits_title'] . "\n" . $list($c['benefits'], false));
    }
    if ($c['steps']) {
        $parts[] = trim((string)$c['steps_title'] . "\n" . $list($c['steps'], true));
    }
    $parts[] = trim(trim((string)$c['free']) . ' ' . trim((string)$c['why']));
    $parts[] = trim((string)$c['closing']);
    return implode("\n\n", array_values(array_filter($parts, fn($x) => trim((string)$x) !== '')));
}

/** Words and problems of the standard email as it is now saved, built for an example stay: ['body','words','problems','subject']. For Settings. */
function pm_tm_email_check(): array
{
    $lead = ['name' => 'Example Lodge', 'city' => 'Zomba', 'contact' => 'Grace Phiri'];
    $body = pm_tm_email_compose($lead);
    $subject = 'Direct bookings for Example Lodge';
    return ['body' => $body, 'subject' => $subject, 'words' => str_word_count($body), 'problems' => pm_outreach_lint($subject, $body, true)];
}

/** WRITER for Travel Malawi: the AI writes the subject, the personal opening and the WhatsApp message; the email is the opening plus the standard part. */
function pm_tm_write(array $leads): array
{
    $s = pm_settings();
    $sender = pm_sender_name();
    $subjectLine = function_exists('pm_email_exp_example') ? pm_email_exp_example('travel') : '';
    $system = pm_agents_company_brief('tiny') . "\nYou are writing outreach for {$s['company_name']} as "
        . ($sender !== '' ? "$sender (use this first name: " . explode(' ', $sender)[0] . ')' : 'a member of the team (no personal name: name the company instead)') . '. For each item write three things. '
        . 'email_subject: under 45 characters, specific to them, truthful, no capitals for emphasis, no exclamation marks, in the subject_style given. '
        . 'opening: at most two short sentences and 40 words: first who you are in one natural sentence (first name and company), then ONE true observation about their place from the evidence or research, such as how you came across it. '
        . 'No greeting, no sign-off, no promises, no numbers, no prices, no link, no exclamation marks. '
        . 'whatsapp: 30 to 45 words, one paragraph: a greeting with the first name if given, who you are, the gain (direct bookings with no commission), one easy question. '
        . 'The rest of the email (what the platform is, the benefits, the set-up steps, the link and that it is free) is a fixed text the app adds, so never describe it. '
        . ($subjectLine !== '' ? $subjectLine . ' ' : '') . PM_AGENT_RULES;
    $slim = array_map(fn($l) => ['id' => $l['id'], 'name' => $l['name'], 'type' => $l['type'] ?? '', 'city' => $l['city'] ?? '', 'contact' => $l['contact'] ?? '', 'evidence' => array_slice((array)($l['evidence'] ?? []), 0, 2),
        'online_gaps' => array_slice((array)($l['social_gaps'] ?? []), 0, 2), 'research' => function_exists('pm_research_brief') ? pm_research_brief($l) : '',
        'subject_style' => function_exists('pm_subject_style') ? pm_subject_style(pm_email_exp_arm((string)$l['id'], 'travel')) : ''], $leads);
    $out = pm_agent_list(pm_agent_json(pm_claude($system, json_encode($slim, JSON_UNESCAPED_UNICODE) . "\nJSON array of {\"id\",\"email_subject\",\"opening\",\"whatsapp\"}. Never greet with the business name.", false, 2000, 'write')));
    $by = [];
    foreach ($out as $x) {
        if (is_array($x) && isset($x['id']) && is_scalar($x['id'])) {
            $by[(string)$x['id']] = $x;
        }
    }
    $txt = fn($v) => is_scalar($v) ? trim(strip_tags((string)$v)) : '';
    $res = [];
    foreach ($leads as $l) {
        $x = $by[(string)$l['id']] ?? [];
        $subject = $txt($x['email_subject'] ?? '');
        if ($subject === '' || mb_strlen($subject) > 70 || str_contains($subject, '!') || preg_match('/^\s*(re|fwd?):/i', $subject)) {
            $subject = 'Direct bookings for ' . mb_substr(trim((string)$l['name']), 0, 40);
        }
        $first = pm_tm_first_name($l);
        $wa = $txt($x['whatsapp'] ?? '');
        if ($wa === '') {
            $me = trim(explode(' ', $sender)[0] ?? '');
            $wa = 'Hello' . ($first !== '' ? " $first" : '') . ', ' . ($me !== '' ? "this is $me from" : 'this is the team at') . ' Travel Malawi. We help independent stays take direct bookings with no commission, and listing is free for now. May I send you the details?';
        }
        $res[] = ['id' => $l['id'], 'email_subject' => $subject, 'email_body' => pm_tm_email_compose($l, pm_tm_clean_opening($txt($x['opening'] ?? ''))), 'whatsapp' => $wa];
    }
    return $res;
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
