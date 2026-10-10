<?php
/**
 * Subject lines, written the way a person writes them: a capital letter first, a capital letter on every place, road, person and brand name, acronyms in capitals
 * (VAT, M1), ordinary lowercase for the rest, no full stop, no exclamation mark. The writers are told how. pm_subject_tidy() is the safety net that fixes what slips
 * through without changing a single word, and pm_subjects_ai_case() asks the AI to do the capitalising of whatever is left: its answer is accepted only when every
 * word is exactly as it was (so the AI can fix capitals and punctuation, never the meaning). Nothing here sends anything.
 */

/** Places, parks, lakes and roads of Malawi, and the brands that appear in subjects, written as they should be. */
const PM_SUBJECT_PROPER = [
    'Malawi', 'Lilongwe', 'Blantyre', 'Mzuzu', 'Zomba', 'Liwonde', 'Mangochi', 'Salima', 'Senga Bay', 'Nkhata Bay', 'Nkhotakota', 'Cape Maclear', 'Monkey Bay', 'Chintheche', 'Karonga',
    'Kasungu', 'Dedza', 'Ntcheu', 'Balaka', 'Chikwawa', 'Nsanje', 'Mulanje', 'Mount Mulanje', 'Thyolo', 'Phalombe', 'Chiradzulu', 'Mwanza', 'Neno', 'Machinga', 'Mchinji', 'Dowa', 'Ntchisi',
    'Rumphi', 'Chitipa', 'Mzimba', 'Likoma', 'Chizumulu', 'Livingstonia', 'Nyika', 'Vwaza Marsh', 'Vwaza', 'Majete', 'Lake Malawi', 'Lake Chilwa', 'Lake Malombe', 'Shire River', 'Shire Highlands',
    'Zomba Plateau', 'Chembe', 'Nkopola', 'Kande Beach', 'Chikale Beach', 'Chilumba', 'Dwangwa', 'Katoto', 'Luwinga', 'Mayoka', 'Njaya', 'Mitundu', 'Kaphiri', 'Chikangawa', 'Mchenga', 'Makokola',
    'Africa', 'Mozambique', 'Zambia', 'Tanzania', 'Zimbabwe', 'South Africa',
    'WhatsApp', 'Facebook', 'Instagram', 'Google', 'TripAdvisor', 'Booking.com', 'Airbnb', 'Expedia', 'YouTube', 'LinkedIn', 'TikTok', 'Travel Malawi', 'ProManaged',
    'VAT', 'SMS', 'POS', 'PDF', 'GPS', 'CCTV', 'WiFi', 'USB', 'PAYE', 'MRA', 'NGO', 'CRM', 'ICT', 'ERP',
];

/** Words that are plain nouns even when a business name carries them: "Bua River Lodge" makes "Bua" a name, not "river" or "lodge". */
const PM_SUBJECT_COMMON = [
    'lodge', 'lodges', 'hotel', 'hotels', 'camp', 'camps', 'camping', 'resort', 'resorts', 'house', 'houses', 'guest', 'guests', 'guesthouse', 'beach', 'lake', 'river', 'view', 'views', 'villa', 'villas',
    'cottage', 'cottages', 'apartment', 'apartments', 'safari', 'chalet', 'chalets', 'backpackers', 'hostel', 'inn', 'motel', 'restaurant', 'bar', 'kitchen', 'cafe', 'school', 'college', 'hospital',
    'clinic', 'shop', 'store', 'supermarket', 'hardware', 'village', 'bay', 'hill', 'hills', 'mountain', 'park', 'garden', 'gardens', 'palace', 'sun', 'star', 'stars', 'moon', 'blue', 'green', 'golden',
    'white', 'black', 'red', 'grand', 'royal', 'new', 'old', 'south', 'north', 'east', 'west', 'central', 'city', 'town', 'the', 'and', 'of', 'in', 'at', 'for', 'by', 'on', 'with', 'your', 'tent', 'tents',
    'eco', 'boutique', 'bed', 'breakfast', 'rooms', 'room', 'stay', 'stays', 'retreat', 'centre', 'center', 'suites', 'suite', 'plaza', 'tower', 'court', 'place', 'home', 'homes', 'farm', 'estate', 'bush',
    'wild', 'wilderness', 'game', 'reserve', 'national', 'shore', 'shores', 'lakeshore', 'lakeside', 'riverside', 'road', 'street', 'drive', 'avenue', 'area', 'plot', 'direct', 'booking', 'bookings',
    'along', 'near', 'opposite', 'next', 'behind', 'beside', 'past', 'close', 'between', 'adjacent', 'junction', 'roundabout', 'turn', 'towards', 'across', 'around', 'from', 'with', 'into', 'over',
    'limited', 'ltd', 'company', 'services', 'service', 'trading', 'group', 'enterprises', 'investments', 'holdings', 'international', 'online', 'travel', 'tours', 'tour', 'lodging', 'accommodation',
];

/** The proper-noun forms to apply to one subject: lowercase phrase => exact spelling. Longest phrases win when they are applied. */
function pm_subject_proper(array $lead = []): array
{
    $map = [];
    foreach (PM_SUBJECT_PROPER as $p) {
        $map[mb_strtolower($p)] = $p;
    }
    $common = array_flip(PM_SUBJECT_COMMON);
    $name = trim((string)($lead['name'] ?? ''));
    $city = trim((string)($lead['city'] ?? ''));
    $addr = trim((string)($lead['address'] ?? ''));
    foreach ([$name, $city, ...(preg_split('/[,;]/u', $addr) ?: [])] as $phrase) { // the business's own name, town and the parts of its address, as written
        $phrase = trim((string)$phrase);
        if (mb_strlen($phrase) >= 3 && preg_match('/\p{L}{3}/u', $phrase) && !preg_match('/^[\d\s\p{P}]+$/u', $phrase)) {
            $map[mb_strtolower($phrase)] = $phrase;
        }
    }
    $head = preg_split('/\s*(?:&|\band\b|[,(\-\x{2013}])\s*/u', $name)[0] ?? ''; // "Blend Lodge & Kitchen" is called "Blend Lodge", "Mayoka Village Beach Lodge" is "Mayoka Village"
    $w = preg_split('/\s+/u', trim($head), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    for ($n = 2; $n <= count($w); $n++) {
        $part = implode(' ', array_slice($w, 0, $n));
        if (mb_strlen($part) >= 6 && !isset($common[mb_strtolower($w[0])])) { // the first word is the name itself ("Blend", "Mayoka"), what follows says what kind of place it is
            $map[mb_strtolower($part)] ??= $part;
        }
    }
    foreach ([$name, $city, $addr] as $src) { // and the capitalised words in them that are names rather than plain nouns
        foreach (preg_split('/[\s,;()\/]+/u', $src) ?: [] as $tok) {
            $t = trim($tok, ".-\"'");
            if (mb_strlen($t) >= 4 && preg_match('/^\p{Lu}/u', $t) && !preg_match('/^\p{Lu}{2,}$/u', $t) && !isset($common[mb_strtolower($t)]) && !isset($map[mb_strtolower($t)])) {
                $map[mb_strtolower($t)] = $t;
            }
        }
    }
    return $map;
}

/** Names of places and brands, acronyms and the first letter, put right in one subject. Words are never changed. */
function pm_subject_tidy(string $subject, array $lead = []): string
{
    $s = trim(preg_replace('/\s+/u', ' ', str_replace(["\u{2019}", "\u{2018}"], "'", $subject)));
    $s = trim(preg_replace('/^(subject\s*:\s*)/i', '', trim($s, " \t\"`")), " \t\"`");
    if ($s === '') {
        return '';
    }
    $s = str_replace('!', '', $s);
    $s = preg_replace('/\?{2,}/', '?', $s);
    $s = rtrim((string)$s, '. ');
    $letters = preg_replace('/[^\p{L}]+/u', '', $s);
    if (mb_strlen($letters) >= 8 && substr_count($s, ' ') >= 1 && preg_match_all('/\p{Lu}/u', $letters) / mb_strlen($letters) > 0.6) { // SHOUTING
        $s = mb_strtolower($s);
    }
    $map = pm_subject_proper($lead);
    uksort($map, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    $alts = implode('|', array_map(fn($k) => preg_quote($k, '/'), array_keys($map)));
    if ($alts !== '') {
        $s = preg_replace_callback('/(?<![\p{L}\p{N}])(' . $alts . ')(?![\p{L}\p{N}])/iu', fn($m) => $map[mb_strtolower($m[1])] ?? $m[1], $s);
    }
    $s = preg_replace_callback('/(?<![\p{L}\p{N}])area\s+(\d{1,3})(?![\p{L}\p{N}])/iu', fn($m) => 'Area ' . $m[1], (string)$s);
    $s = preg_replace_callback('/(?<![\p{L}\p{N}])(m[1-9])(?![\p{L}\p{N}\/])/u', fn($m) => strtoupper($m[1]), (string)$s);
    if (preg_match('/^([^\p{L}\p{N}]*)(\p{Ll})/u', (string)$s, $m)) { // the first letter (a subject that starts with a number stays as it is)
        $s = $m[1] . mb_strtoupper($m[2]) . mb_substr((string)$s, mb_strlen($m[1]) + 1);
    }
    return trim((string)$s);
}

/** Does the AI's version keep every word of the original? Only capitals, spaces and punctuation may differ. */
function pm_subject_same_words(string $a, string $b): bool
{
    $n = fn(string $s) => trim((string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($s)));
    return $n($a) === $n($b) && $n($a) !== '';
}

/**
 * The AI capitalises and punctuates subjects it is given: [id => ['subject','name','city','address']] -> [id => fixed subject] for those that changed.
 * An answer that changes a word, or answers for an id that was not asked, is thrown away. Returns [] without an AI key or when the call fails.
 */
function pm_subjects_ai_case(array $rows): array
{
    $out = [];
    $system = 'You are the subject-line editor for ' . (pm_settings()['company_name'] ?? 'a small Malawian business') . '. Each item is an email subject line. Rewrite each one so it is correctly capitalised and punctuated: '
        . 'a capital letter first, a capital letter on every proper noun (towns, areas, roads, parks, lakes, rivers, people, business and brand names) and on acronyms (VAT, M1, WhatsApp), ordinary lowercase for every other word, '
        . 'no full stop at the end, no exclamation marks. Change NOTHING else: keep exactly the same words in the same order, add none and remove none. The business name, town and address are only there to tell you which words are names. '
        . 'Reply JSON only.';
    foreach (array_chunk($rows, 20, true) as $chunk) {
        $items = [];
        foreach ($chunk as $id => $r) {
            $items[] = ['id' => (string)$id, 'subject' => (string)$r['subject'], 'business' => (string)($r['name'] ?? ''), 'town' => (string)($r['city'] ?? ''), 'address' => (string)($r['address'] ?? '')];
        }
        try {
            $res = pm_agent_json(pm_claude($system, json_encode($items, JSON_UNESCAPED_UNICODE) . "\nJSON: {\"items\":[{\"id\":\"\",\"subject\":\"\"}]}", false, 200 + 60 * count($items), 'cheap'));
        } catch (Throwable) {
            continue;
        }
        $list = is_array($res) ? (array_is_list($res) ? $res : (array)($res['items'] ?? [])) : [];
        foreach ($list as $x) {
            if (!is_array($x) || !isset($x['id']) || !is_scalar($x['id']) || !is_scalar($x['subject'] ?? null)) {
                continue;
            }
            $id = (string)$x['id'];
            $had = $rows[$id] ?? ($rows[(int)$id] ?? null);
            if ($had === null || !pm_subject_same_words((string)$had['subject'], (string)$x['subject'])) {
                continue;
            }
            $fixed = pm_subject_tidy((string)$x['subject'], (array)($had['lead'] ?? $had));
            if ($fixed !== '' && $fixed !== (string)$had['subject']) {
                $out[$id] = $fixed;
            }
        }
    }
    return $out;
}

/** Writers' rows ([id, email_subject, ...]) with their subjects put right by code (no AI call while writing: the writers are told how, and "Fix subject lines" asks the AI to look at the rest). $ai = true adds that AI look. */
function pm_subjects_polish(array $rows, array $leads, bool $ai = false): array
{
    $by = [];
    foreach ($leads as $l) {
        $by[(string)($l['id'] ?? '')] = $l;
    }
    $ask = [];
    foreach ($rows as $i => $r) {
        if (!is_array($r) || trim((string)($r['email_subject'] ?? '')) === '') {
            continue;
        }
        $l = $by[(string)($r['id'] ?? '')] ?? [];
        $rows[$i]['email_subject'] = pm_subject_tidy((string)$r['email_subject'], $l);
        $ask[$i] = ['subject' => $rows[$i]['email_subject'], 'name' => $l['name'] ?? '', 'city' => $l['city'] ?? '', 'address' => $l['address'] ?? '', 'lead' => $l];
    }
    if ($ai && $ask && (pm_agents_ready() || isset($GLOBALS['PM_AI_STUB']))) {
        foreach (pm_subjects_ai_case($ask) as $i => $fixed) {
            $rows[$i]['email_subject'] = $fixed;
        }
    }
    return $rows;
}

/** Unsent drafts of the current business whose subject code can still improve: ['leadid|drafts' => [lead id, key, subject, tidied]]. Hand-edited drafts are left as their writer typed them. */
function pm_subjects_pending(array $leads): array
{
    $brand = pm_brand();
    $out = [];
    foreach ($leads as $k => $l) {
        if (($l['brand'] ?? 'promanaged') !== $brand || in_array($l['status'] ?? '', ['won', 'lost', 'optout'], true)) {
            continue;
        }
        foreach (['drafts' => empty($l['sent']), 'followup_draft' => ($l['status'] ?? '') === 'contacted'] as $key => $open) {
            $sub = trim((string)($l[$key]['email_subject'] ?? ''));
            if (!$open || $sub === '' || !empty($l[$key]['edited_at']) || !empty($l[$key]['subject_checked'])) {
                continue;
            }
            $t = pm_subject_tidy($sub, $l);
            if ($t !== $sub) {
                $out[(string)$k . '|' . $key] = ['lead' => (string)$k, 'key' => $key, 'subject' => $sub, 'tidy' => $t];
            }
        }
    }
    return $out;
}

/**
 * Puts the subject lines of the current business's unsent drafts right: code first, then the AI for the rest of the names. Marks what was checked so it is not asked again.
 * Returns ['fixed' => n, 'checked' => n, 'ai' => bool].
 */
function pm_subjects_fix_now(bool $ai = true): array
{
    $brand = pm_brand();
    $leads = array_filter(pm_leads(), fn($l) => ($l['brand'] ?? 'promanaged') === $brand);
    $todo = [];
    foreach ($leads as $k => $l) {
        if (in_array($l['status'] ?? '', ['won', 'lost', 'optout'], true)) {
            continue;
        }
        foreach (['drafts' => empty($l['sent']), 'followup_draft' => ($l['status'] ?? '') === 'contacted'] as $key => $open) {
            $sub = trim((string)($l[$key]['email_subject'] ?? ''));
            if ($open && $sub !== '' && empty($l[$key]['edited_at']) && empty($l[$key]['subject_checked'])) {
                $todo[(string)$k . '|' . $key] = ['lead' => (string)$k, 'key' => $key, 'subject' => pm_subject_tidy($sub, $l), 'name' => $l['name'] ?? '', 'city' => $l['city'] ?? '', 'address' => $l['address'] ?? '', 'old' => $sub, 'l' => $l];
            }
        }
    }
    $aiUsed = false;
    if ($ai && $todo && (pm_agents_ready() || isset($GLOBALS['PM_AI_STUB']))) {
        $asks = array_map(fn($t) => ['subject' => $t['subject'], 'name' => $t['name'], 'city' => $t['city'], 'address' => $t['address'], 'lead' => $t['l']], $todo);
        $fix = pm_subjects_ai_case($asks);
        $aiUsed = true;
        foreach ($fix as $k => $v) {
            $todo[$k]['subject'] = $v;
        }
    }
    $fixed = 0;
    pm_update('leads', function (array $cur) use ($todo, &$fixed, $aiUsed) {
        foreach ($todo as $t) {
            $id = $t['lead'];
            if (!isset($cur[$id][$t['key']]) || trim((string)($cur[$id][$t['key']]['email_subject'] ?? '')) !== $t['old'] || !empty($cur[$id][$t['key']]['edited_at'])) {
                continue; // changed by someone while this ran
            }
            if ($t['subject'] !== $t['old']) {
                $cur[$id][$t['key']]['email_subject'] = $t['subject'];
                $fixed++;
            }
            if ($aiUsed) {
                $cur[$id][$t['key']]['subject_checked'] = 1;
            }
        }
        return $cur;
    }, fn() => []);
    return ['fixed' => $fixed, 'checked' => count($todo), 'ai' => $aiUsed];
}
