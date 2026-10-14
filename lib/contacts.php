<?php
/**
 * Finding who runs a business and how to reach it: the owner's or manager's name, an email address, a WhatsApp number.
 *
 * The Contact Finder used to ask a web-search AI and take its word for it, which mostly came back with only what the lead already had. It now READS THE BUSINESS'S OWN WEBSITE first
 * (home page plus the about, team, owner and contact pages, fetched safely):
 *   - email addresses, phone numbers and WhatsApp links/numbers are taken straight from the page by code (no AI);
 *   - a person is accepted only when the AI points to the exact sentence on the page that names them as owner, founder, director, manager or host, the sentence is really on
 *     the page (checked in code), the name is in that sentence, and the role is not kitchen, restaurant, housekeeping or front-desk staff. Because the page is the business's own, the
 *     name can be used in the greeting; a name found anywhere else stays a "possible" name for the owner to confirm.
 * Only when the pages do not give a name (or an email) does the web search run as a second chance. Nothing here sends anything, and nothing is guessed.
 */

/** Is this a person's name we may use in a greeting? Letters only (no digits, addresses or links), at least two letters, and not a department. */
function pm_contact_name_ok(string $name): bool
{
    $name = trim($name);
    return $name !== '' && !preg_match('/\d|@|https?:/', $name) && mb_strlen(preg_replace('/[^\p{L}]/u', '', $name)) >= 2
        && !preg_match('/\b(reservations?|reception|info|sales|bookings?|management|admin|team|office|manager|owner|director)\b/i', $name);
}

/** WhatsApp numbers a page shows: wa.me and api.whatsapp.com links, whatsapp:// links, and numbers written next to the word WhatsApp. Digits with the country code, mobiles only. */
function pm_page_whatsapp(string $html): array
{
    $t = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $raw = [];
    if (preg_match_all('~(?:wa\.me/|whatsapp\.com/send/?\?[^"\'\s>]*?phone=|whatsapp://send/?\?[^"\'\s>]*?phone=)\+?(\d{9,15})~i', $t, $m)) {
        $raw = array_merge($raw, $m[1]);
    }
    $plain = preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $t);
    $plain = preg_replace('#<[^>]+>#', ' ', (string)$plain);
    if (preg_match_all('/whats\s?app[^0-9+]{0,30}(\+?\d[\d\s\-().]{7,17}\d)/i', (string)$plain, $m)) {
        $raw = array_merge($raw, $m[1]);
    }
    $out = [];
    foreach ($raw as $r) {
        [$n, $kind] = pm_wa_number((string)$r);
        if ($n !== '' && $kind !== 'landline') {
            $out[$n] = 1;
        }
    }
    return array_slice(array_map('strval', array_keys($out)), 0, 3);
}

/**
 * The business's own website, read for contact details: the home page and up to four pages about the team, owner, story or contact.
 * Returns ['url', 'pages' => [['url','title','text']], 'emails', 'phones', 'whatsapp', 'note']. Nothing given or a site that will not open gives empty lists and a note.
 */
function pm_contact_site(string $website, int $max = 5): array
{
    $r = ['url' => '', 'pages' => [], 'emails' => [], 'phones' => [], 'whatsapp' => [], 'note' => ''];
    $url = pm_study_url($website);
    if ($url === '') {
        return $r;
    }
    $r['url'] = $url;
    $host = pm_study_host($url);
    $read = function (array $f) use (&$r, $host) {
        $t = pm_study_text($f['html'], $f['url']);
        $r['pages'][] = ['url' => $f['url'], 'title' => $t['title'], 'text' => $t['text']];
        $r['emails'] = array_values(array_unique(array_merge($r['emails'], function_exists('pm_page_emails') ? pm_page_emails($f['html'], $host, 6) : $t['emails'])));
        $r['phones'] = array_slice(array_values(array_unique(array_merge($r['phones'], $t['phones']))), 0, 4);
        $r['whatsapp'] = array_slice(array_values(array_unique(array_merge($r['whatsapp'], pm_page_whatsapp($f['html'])))), 0, 3);
        return $t;
    };
    $home = pm_study_fetch($url);
    if (!$home['ok'] || trim($home['html']) === '') {
        $r['note'] = 'The website ' . $host . ' did not open (' . ($home['why'] ?: 'no content') . ').';
        return $r;
    }
    $t = $read($home);
    $cand = [];
    foreach ($t['links'] as $u => $label) {
        $path = strtolower((string)parse_url($u, PHP_URL_PATH));
        $s = $path . ' ' . strtolower((string)$label);
        if ($path === '' || $path === '/' || rtrim($u, '/') === rtrim($home['url'], '/') || preg_match('#(privacy|terms|cookie|login|cart|checkout|wp-content|feed|tag/|category/|\.(pdf|jpe?g|png|gif|zip))#', $s)) {
            continue;
        }
        $sc = preg_match('#(owner|our-team|our team|meet|team|staff|management|about|who-we|who we|story|host)#', $s) ? 5 : (preg_match('#(contact|reach|find-us|find us|enquir|reserv|book)#', $s) ? 3 : 0);
        if ($sc > 0) {
            $cand[$u] = $sc;
        }
    }
    arsort($cand);
    $seen = [rtrim($home['url'], '/') => 1];
    foreach (array_keys($cand) as $u) {
        $key = rtrim((string)strtok($u, '#'), '/');
        if (isset($seen[$key])) {
            continue; // "/contact-us" and "/contact-us/" are one page
        }
        $seen[$key] = 1;
        if (count($r['pages']) >= $max) {
            break;
        }
        $g = pm_study_fetch($u);
        if ($g['ok'] && trim($g['html']) !== '') {
            $read($g);
        }
    }
    return $r;
}

/** Words that start with a capital on a lodge's page but are never a person's name. */
const PM_NOT_NAMES = ['The', 'Our', 'We', 'Us', 'Team', 'Lodge', 'Lake', 'Malawi', 'Camp', 'Hotel', 'Resort', 'Guest', 'House', 'Beach', 'Safari', 'Village', 'Restaurant', 'Africa', 'African',
    'Lilongwe', 'Blantyre', 'Mzuzu', 'Zomba', 'Mangochi', 'Salima', 'Liwonde', 'Mulanje', 'Contact', 'Home', 'About', 'Welcome', 'Booking', 'Bookings', 'Reservations', 'Management', 'Company',
    'Limited', 'Ltd', 'Group', 'Services', 'Office', 'Email', 'Phone', 'Tel', 'Call', 'Mr', 'Mrs', 'Ms', 'Dr', 'Madam', 'Sir', 'And', 'Of', 'In', 'At', 'For', 'With', 'This', 'Your', 'Page',
    'Facebook', 'Instagram', 'Google', 'Tripadvisor', 'Airbnb', 'Manager', 'Owner', 'Director', 'Founder', 'Host', 'Hosts', 'General', 'Managing', 'Chalets', 'Cottage', 'Cottages', 'Rooms', 'Bar'];

/** The lines of the pages that mention an owner, manager or host (what the AI needs to read, and all it is sent): [['url','text']]. */
function pm_contact_role_lines(array $pages, int $max = 900): array
{
    $out = [];
    foreach ($pages as $p) {
        $keep = [];
        foreach ((array)preg_split('/(?<!Mr\.|Dr\.|Ms\.|Mrs\.|St\.)(?<=[.!?])\s+|\R/u', (string)($p['text'] ?? '')) as $line) {
            $line = trim((string)$line);
            if ($line !== '' && mb_strlen($line) < 300 && preg_match('/\b(owner|owned|founder|founded|proprietor|director|manager|managed|managing|hosts?|hosted|run by|started by|your hosts?|meet)\b/iu', $line)
                && !preg_match('/\b(restaurant|kitchen|chef|waiter|housekeep\w*|front desk|reception\w*)\s+manager\b/iu', $line)) {
                $keep[] = $line;
            }
        }
        if ($keep) {
            $out[] = ['url' => (string)$p['url'], 'text' => mb_substr(implode("\n", array_unique($keep)), 0, $max)];
        }
    }
    return $out;
}

/**
 * The owner or manager named in plain words on the business's own pages, found by code (no AI): "Owner: Grace Banda", "Grace Banda, General Manager", "run by John and Mary",
 * "your hosts Tom and Ann". ['name','title','source','quote'] or [] when the pages say nothing that plain.
 */
function pm_contact_free_name(array $pages): array
{
    $N = '((?:\p{Lu}[\p{Ll}\'’-]+)(?:\s+\p{Lu}[\p{Ll}\'’-]+){0,2})';
    $R = '(?i:(co-?owner|owner|co-?founder|founder|proprietor|proprietress|general manager|managing director|director|manager|hosts?))';
    $T = '(?:(?:Mr|Mrs|Ms|Dr|Madam)\.?\s+)?';
    $pats = ['/\b' . $R . '\s*(?:[:\-–—,]|is|was)\s*' . $T . $N . '/u' => [1, 2],
        '/' . $N . '\s*(?:[,\-–—(]|is)\s*(?i:the\s+|our\s+)?' . $R . '\b/u' => [2, 1],
        '/\b(?i:(owned|run|managed|founded|hosted|started))\s+(?i:and\s+\w+\s+)?(?i:by)\s+' . $T . $N . '/u' => [1, 2],
        '/\b(?i:(your|our)\s+hosts?)\s*,?\s*(?i:are\s+)?' . $T . $N . '/u' => [1, 2]];
    $rank = fn(string $r) => preg_match('/owner|own|founder|found|proprietor|start/i', $r) ? 1 : (preg_match('/managing|director/i', $r) ? 2 : (preg_match('/general/i', $r) ? 3 : 4));
    $best = [];
    foreach ($pages as $p) {
        foreach ((array)preg_split('/(?<!Mr\.|Dr\.|Ms\.|Mrs\.|St\.)(?<=[.!?])\s+|\R/u', (string)($p['text'] ?? '')) as $line) {
            if (mb_strlen((string)$line) > 300 || preg_match('/\b(restaurant|kitchen|chef|waiter|housekeep\w*|front desk|reception\w*|guest|review\w*)\b/iu', (string)$line)) {
                continue; // a staff role, or a guest writing a review
            }
            foreach ($pats as $re => [$ri, $ni]) {
                if (!preg_match($re, (string)$line, $m)) {
                    continue;
                }
                $words = [];
                foreach (preg_split('/\s+/u', trim($m[$ni])) ?: [] as $w) {
                    if (in_array($w, PM_NOT_NAMES, true)) {
                        if ($ni === 1) { // the words before ", manager": one that is not a name ("Kande Beach Resort, manager") means it is not a person
                            $words = [];
                        }
                        break; // after "Owner: Grace Banda Welcome": the name stops at the first word that is not one
                    }
                    $words[] = $w;
                }
                $name = implode(' ', $words);
                if ($name === '' || !pm_contact_name_ok($name)) {
                    continue;
                }
                $role = strtolower(trim($m[$ri]));
                $role = preg_match('/^(owned|run|founded|started)$/', $role) ? 'Owner' : (preg_match('/^(managed)$/', $role) ? 'Manager' : (preg_match('/host/', $role) ? 'Host' : ucwords($role)));
                if (!$best || $rank($role) < $rank((string)$best['title'])) {
                    $best = ['name' => $name, 'title' => $role, 'source' => (string)$p['url'], 'quote' => mb_substr(trim((string)$line), 0, 200)];
                }
            }
        }
    }
    return $best;
}

/** A possible name the owner has not confirmed becomes the contact when its role is the owner's or a manager's (not kitchen, restaurant or front-desk staff), so the greeting uses it. */
function pm_contact_promote_hint(array &$lead): bool
{
    if (trim((string)($lead['contact'] ?? '')) !== '' && isset($lead['contact_hint'])) {
        unset($lead['contact_hint']); // the name is known: the old guess goes
    }
    if (trim((string)($lead['contact'] ?? '')) !== '' || !($h = pm_contact_hint_parts($lead)) || $h['not_owner']
        || !preg_match('/owner|founder|proprietor|director|manager|host|managing/i', $h['title']) || preg_match('/restaurant|kitchen|chef|front desk|reception|housekeep|assistant/i', $h['title'])) {
        return false;
    }
    $lead['contact'] = $h['name'];
    $lead['contact_title'] = $lead['contact_title'] ?? $h['title'];
    unset($lead['contact_hint']);
    pm_lead_note($lead, 'Greeting now uses ' . $h['name'] . ' (' . $h['title'] . ($h['source'] !== '' ? ', from ' . $h['source'] : '') . ')');
    function_exists('pm_dm_personalise') && pm_dm_personalise($lead);
    return true;
}

/**
 * NAME SEARCH for several leads in ONE web call (instead of one call each): the owner's or manager's name of each, with the page that states it.
 * Returns [id => ['contact','contact_title','source']]; a lead nobody found is left out.
 */
function pm_agent_names_batch(array $leads): array
{
    $system = 'You find who runs each business: the owner, founder, managing director, general manager, manager or host, by web search (their website, Facebook, TripAdvisor or Google review replies signed by the owner or manager, Airbnb or Booking.com host, news, LinkedIn). '
        . 'Only a name a page ties to THIS business in THIS town, with that page\'s URL. Never guess; never kitchen, restaurant or front-desk staff, guests or reviewers. Leave out a business you cannot find a name for. ' . PM_AGENT_RULES;
    $list = implode("\n", array_map(fn($l) => $l['id'] . ': ' . $l['name'] . ', ' . ($l['city'] ?? '') . (!empty($l['website']) ? ' (' . $l['website'] . ')' : ''), $leads));
    $out = pm_agent_json(pm_claude($system, $list . "\nJSON: {\"found\":[{\"id\",\"name\",\"role\",\"source\":\"URL\"}]}", true, 120 + 60 * count($leads), 'cheap'));
    $res = [];
    $ids = array_column($leads, 'id');
    foreach ((array)($out['found'] ?? (is_array($out) && array_is_list($out) ? $out : [])) as $x) {
        $id = is_array($x) && is_scalar($x['id'] ?? null) ? (string)$x['id'] : '';
        $n = trim(strip_tags((string)($x['name'] ?? '')));
        if (in_array($id, $ids, true) && pm_contact_name_ok($n) && preg_match('#^https?://#i', (string)($x['source'] ?? ''))) {
            $res[$id] = ['contact' => mb_substr($n, 0, 80), 'contact_title' => mb_substr(trim(strip_tags((string)($x['role'] ?? ''))), 0, 60), 'source' => (string)$x['source']];
        }
    }
    return $res;
}

/**
 * The owner or manager named on the business's own pages: ['name','title','source' (page address),'quote'] or []. The AI points to the sentence; code checks that the sentence is
 * on the page, that the name is in it, that the name is a person's and that the role is the owner's or the manager's (not kitchen, restaurant, housekeeping or front-desk staff).
 */
function pm_contact_people(array $lead, array $site): array
{
    $pages = array_values(array_filter((array)$site['pages'], fn($p) => trim((string)($p['text'] ?? '')) !== ''));
    if (!$pages) {
        return [];
    }
    if ($free = pm_contact_free_name($pages)) { // the page says it plainly ("Owner: Grace Banda", "run by John and Mary"): no AI
        return $free;
    }
    $snip = pm_contact_role_lines($pages);
    if ($snip === []) {
        return []; // no line on their pages mentions an owner, manager or host: nothing for the AI to find
    }
    $pages = $snip; // the AI reads only the lines that mention a role, not whole pages (a tenth of the tokens)
    $system = 'You read pages from the website of ' . (string)($lead['name'] ?? 'a business') . '. List the people the pages themselves name as running THIS business: owner, founder, director, general manager, manager or host. '
        . 'For each give "quote": the exact sentence or line from the pages that names them, copied word for word. Never guess, and never complete a name from anything except the pages. '
        . 'Ignore guests, reviewers, customers quoted in testimonials, staff in the kitchen, restaurant, housekeeping or front desk, and people who belong to other organisations. '
        . 'If the pages name nobody, answer {"people":[]}. Reply JSON only.';
    $user = '';
    foreach (array_slice($pages, 0, 5) as $p) {
        $user .= 'PAGE ' . $p['url'] . "\n" . mb_substr((string)$p['text'], 0, 900) . "\n\n";
    }
    $user .= 'JSON: {"people":[{"name":"full name","role":"as written on the page","quote":"the exact sentence"}]}';
    try {
        $res = pm_agent_json(pm_claude($system, $user, false, 700, 'cheap'));
    } catch (Throwable) {
        return [];
    }
    $list = is_array($res) ? (array_is_list($res) ? $res : (array)($res['people'] ?? [])) : [];
    $norm = fn(string $s) => trim((string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($s)));
    $best = [];
    $rank = fn(string $role) => preg_match('/owner|founder|proprietor|co-?owner/i', $role) ? 1 : (preg_match('/managing|director|ceo|chairman/i', $role) ? 2 : (preg_match('/general manager/i', $role) ? 3 : 4));
    foreach ($list as $x) {
        if (!is_array($x) || !is_scalar($x['name'] ?? null) || !is_scalar($x['quote'] ?? null)) {
            continue;
        }
        $name = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$x['name'])));
        $role = trim(strip_tags((string)($x['role'] ?? '')));
        $q = $norm((string)$x['quote']);
        if (!pm_contact_name_ok($name) || !preg_match('/\p{Lu}/u', $name) || mb_strlen($q) < 12 || !str_contains(' ' . $q . ' ', ' ' . $norm($name) . ' ')) {
            continue;
        }
        if (!preg_match('/owner|founder|proprietor|director|manager|managing|host|ceo|chairman|principal|partner/i', $role) || preg_match('/restaurant|kitchen|chef|waiter|housekeep|front desk|receptionist|barman|bartender|driver|guard/i', $role)) {
            continue;
        }
        foreach ($pages as $p) {
            if (str_contains($norm((string)$p['text']), $q)) {
                $cand = ['name' => $name, 'title' => mb_substr($role, 0, 60), 'source' => (string)$p['url'], 'quote' => mb_substr((string)$x['quote'], 0, 200)];
                if (!$best || $rank($role) < $rank((string)$best['title'])) {
                    $best = $cand;
                }
                break;
            }
        }
    }
    return $best;
}

/**
 * Everything the Contact Finder can learn about one lead, in the shape pm_apply_contact() takes: ['contact','contact_title','source','email','phone','whatsapp','website','read' => pages read].
 * The website first (code reads the details, the AI points to the name), then the web search for what is still missing.
 */
function pm_contact_find(array $lead): array
{
    $d = ['contact' => '', 'contact_title' => '', 'source' => '', 'email' => '', 'phone' => '', 'whatsapp' => '', 'website' => '', 'read' => 0, 'note' => ''];
    $site = trim((string)($lead['website'] ?? '')) !== '' ? pm_contact_site((string)$lead['website']) : ['pages' => [], 'emails' => [], 'phones' => [], 'whatsapp' => [], 'note' => '', 'url' => ''];
    $d['read'] = count($site['pages']);
    $d['note'] = (string)$site['note'];
    $free = '/@(gmail|yahoo|outlook|hotmail|live|icloud)\./i';
    $host = pm_study_host((string)($site['url'] ?: ($lead['website'] ?? '')));
    foreach ($site['emails'] as $e) { // an address on their own domain, or a free-mail one the page shows (small lodges use them); never someone else's company
        $dom = substr((string)strrchr($e, '@'), 1);
        if ($host !== '' && ($dom === $host || str_ends_with($host, '.' . $dom) || str_ends_with($dom, '.' . $host))) {
            $d['email'] = $e;
            break;
        }
        if ($d['email'] === '' && preg_match($free, $e)) {
            $d['email'] = $e;
        }
    }
    $d['phone'] = (string)($site['phones'][0] ?? '');
    $d['whatsapp'] = $site['whatsapp'] ? '+' . $site['whatsapp'][0] : '';
    $d['website'] = (string)($site['url'] ?? '');
    if (trim((string)($lead['contact'] ?? '')) === '' && $site['pages']) {
        $p = pm_contact_people($lead, $site);
        if ($p) {
            $d['contact'] = $p['name'];
            $d['contact_title'] = $p['title'];
            $d['source'] = $p['source'];
        }
    }
    return $d;
}

/**
 * CONTACT FINDER for one lead: the website first, then the web search for what is still missing (the owner's name, or an email address).
 * Returns the same keys the web search always returned, so the callers are unchanged.
 */
function pm_agent_contact(array $lead, bool $nameSearch = true): array // $nameSearch false: a missing name is left to the batched name search (pm_agent_names_batch)
{
    $d = pm_contact_find($lead);
    $haveName = trim((string)($lead['contact'] ?? '')) !== '' || $d['contact'] !== '';
    $haveMail = trim((string)($lead['email'] ?? '')) !== '' || $d['email'] !== '';
    if ((!$haveName && $nameSearch) || !$haveMail) {
        $found = [];
        try {
            $found = pm_agent_contact_search($lead);
        } catch (Throwable $e) {
            if ($d['read'] === 0 && $d['email'] === '' && $d['whatsapp'] === '') {
                throw $e; // nothing from the website and the search failed too: let the caller say so
            }
        }
        foreach (['contact', 'contact_title', 'email', 'phone', 'whatsapp', 'website'] as $k) {
            if (trim((string)($d[$k] ?? '')) === '' && !empty($found[$k]) && is_scalar($found[$k])) {
                $d[$k] = trim((string)$found[$k]);
                if ($k === 'contact') {
                    $d['source'] = (string)($found['source'] ?? $found['contact_source'] ?? '');
                }
            }
        }
    }
    return $d;
}

/** What was added to a lead, in words: ['the owner's name', 'an email address', ...]. Fills only what the lead lacks. */
function pm_contact_apply(array &$lead, array $d): array
{
    $added = [];
    $had = ['contact' => trim((string)($lead['contact'] ?? '')) !== '', 'hint' => !empty($lead['contact_hint'])];
    if (trim((string)($lead['email'] ?? '')) === '' && filter_var(trim((string)($d['email'] ?? '')), FILTER_VALIDATE_EMAIL)) {
        $lead['email'] = strtolower(trim((string)$d['email']));
        unset($lead['email_bad'], $lead['email_rejected']);
        $added[] = 'an email address';
    }
    if (trim((string)($lead['phone'] ?? '')) === '' && trim((string)($d['phone'] ?? '')) !== '') {
        $lead['phone'] = trim((string)$d['phone']);
        $added[] = 'a phone number';
    }
    if (trim((string)($lead['whatsapp'] ?? '')) === '' && trim((string)($d['whatsapp'] ?? '')) !== '') { // a number the page itself calls WhatsApp is better evidence than a plain phone
        [$n, $k] = pm_wa_number((string)$d['whatsapp']);
        if ($n !== '' && $k !== 'landline') {
            $lead['whatsapp'] = '+' . $n;
            $added[] = 'a WhatsApp number';
        }
    }
    if (trim((string)($lead['website'] ?? '')) === '' && trim((string)($d['website'] ?? '')) !== '') {
        $lead['website'] = trim((string)$d['website']);
    }
    pm_apply_contact($lead, $d);
    if (!$had['contact'] && trim((string)($lead['contact'] ?? '')) !== '') {
        $added[] = 'the owner or manager\'s name (' . $lead['contact'] . ')';
    } elseif (!$had['hint'] && !empty($lead['contact_hint'])) {
        $added[] = 'a possible name to confirm (' . $lead['contact_hint'] . ')';
    }
    $lead['contact_checked'] = date('Y-m-d');
    function_exists('pm_dm_personalise') && pm_dm_personalise($lead); // a waiting draft that said "Hello," greets the owner by name
    return $added;
}

/** On demand for one lead: look for the missing name, email and WhatsApp number now. Returns [ok, message]. */
function pm_contact_find_one(string $id): array
{
    $lead = pm_leads()[$id] ?? null;
    if (!$lead) {
        return [false, 'That lead was not found.'];
    }
    $was = pm_brand();
    pm_brand_set((string)($lead['brand'] ?? 'promanaged'));
    try {
        $d = pm_agent_contact($lead);
    } catch (Throwable $e) {
        return [false, 'The search for ' . $lead['name'] . ' failed: ' . mb_substr($e->getMessage(), 0, 120) . '. Try again, or type what you know in the boxes.'];
    } finally {
        pm_brand_set($was);
    }
    $added = [];
    pm_update('leads', function (array $cur) use ($id, $d, &$added) {
        if (isset($cur[$id])) {
            $added = pm_contact_apply($cur[$id], $d);
            pm_lead_note($cur[$id], $added ? 'Contact finder added ' . implode(', ', $added) : 'Contact finder looked and found nothing new');
        }
        return $cur;
    }, fn() => []);
    $looked = (int)($d['read'] ?? 0) > 0 ? 'read ' . (int)$d['read'] . ' page' . ((int)$d['read'] === 1 ? '' : 's') . ' of their website and searched the web' : 'searched the web' . (trim((string)($lead['website'] ?? '')) === '' ? ' (they have no website on file)' : ' (their website would not open)');
    if (!$added) {
        return [true, 'Nothing new found for ' . $lead['name'] . ': we ' . $looked . '. Type what you know in the boxes on the card.'];
    }
    return [true, 'For ' . $lead['name'] . ' we found ' . implode(' and ', array_slice($added, 0, 4)) . ' (we ' . $looked . ').'];
}

/** The name, role and source behind a "possible contact" on a lead, when it is a person's name. [] when there is none or it is not a person. */
function pm_contact_hint_parts(array $lead): array
{
    $h = trim((string)($lead['contact_hint'] ?? ''));
    if ($h === '' || !preg_match('/^(?<n>[^(,]+?)(?:\s*\((?<t>[^)]*)\))?(?:,\s*(?:from\s+(?<s>\S+)|not the owner))?\s*$/u', $h, $m)) {
        return [];
    }
    $n = trim($m['n']);
    return pm_contact_name_ok($n) ? ['name' => $n, 'title' => trim($m['t'] ?? ''), 'source' => trim($m['s'] ?? ''), 'not_owner' => str_contains($h, 'not the owner')] : [];
}

/** The owner confirms a possible name: it becomes the contact the greeting uses. Returns [ok, message]. */
function pm_contact_use_hint(string $id): array
{
    $parts = pm_contact_hint_parts(pm_leads()[$id] ?? []);
    if (!$parts) {
        return [false, 'There is no name to use on this lead.'];
    }
    pm_update('leads', function (array $cur) use ($id, $parts) {
        if (isset($cur[$id]) && trim((string)($cur[$id]['contact'] ?? '')) === '') {
            $cur[$id]['contact'] = $parts['name'];
            if ($parts['title'] !== '' && empty($cur[$id]['contact_title'])) {
                $cur[$id]['contact_title'] = $parts['title'];
            }
            unset($cur[$id]['contact_hint']);
            pm_lead_note($cur[$id], 'Contact name confirmed by you: ' . $parts['name']);
            function_exists('pm_dm_personalise') && pm_dm_personalise($cur[$id]);
        }
        return $cur;
    }, fn() => []);
    return [true, $parts['name'] . ' is now the contact: the greeting uses their first name (a waiting draft nobody edited is updated now).'];
}

/**
 * Saves details the owner typed for a lead: only the boxes that were filled in. An email must be an address; a WhatsApp number must be a mobile (a landline is kept as the phone).
 * Returns [ok, message].
 */
function pm_contact_save(string $id, array $in): array
{
    $email = strtolower(trim((string)($in['email'] ?? '')));
    $wa = trim((string)($in['whatsapp'] ?? ''));
    $phone = mb_substr(trim((string)($in['phone'] ?? '')), 0, 40);
    $name = mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string)($in['contact'] ?? '')))), 0, 80);
    $said = [];
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [false, '"' . mb_substr($email, 0, 60) . '" is not an email address. Nothing was saved.'];
    }
    $waN = '';
    if ($wa !== '') {
        [$n, $k] = pm_wa_number($wa);
        if ($n === '') {
            return [false, '"' . mb_substr($wa, 0, 40) . '" is not a phone number. Nothing was saved.'];
        }
        if ($k === 'landline') {
            $phone = $phone !== '' ? $phone : $wa; // a landline has no WhatsApp: it is still a way to call them
            $said[] = 'that looks like a landline, so it was saved as their phone number, not as WhatsApp';
        } else {
            $waN = '+' . $n;
        }
    }
    if ($name !== '' && !pm_contact_name_ok($name)) {
        return [false, '"' . mb_substr($name, 0, 40) . '" does not look like a person\'s name. Nothing was saved.'];
    }
    $ok = false;
    pm_update('leads', function (array $cur) use ($id, $email, $waN, $phone, $name, &$ok, &$said) {
        if (!isset($cur[$id])) {
            return $cur;
        }
        $ok = true;
        $l = &$cur[$id];
        if ($email !== '') {
            if (strcasecmp((string)($l['email'] ?? ''), $email) !== 0) {
                unset($l['email_bad'], $l['email_rejected']);
            }
            $l['email'] = $email;
            $l['email_src'] = 'owner';
            $said[] = 'email saved';
        }
        if ($waN !== '') {
            $l['whatsapp'] = $waN;
            $said[] = 'WhatsApp number saved';
        }
        if ($phone !== '') {
            $l['phone'] = $phone;
        }
        if ($name !== '') {
            $l['contact'] = $name;
            unset($l['contact_hint']);
            $said[] = 'contact name saved';
        }
        pm_lead_note($l, 'Contact details added by you');
        return $cur;
    }, fn() => []);
    if (!$ok) {
        return [false, 'That lead was not found.'];
    }
    return [true, ucfirst(implode('; ', $said ?: ['nothing to save: the boxes were empty'])) . '.'];
}
