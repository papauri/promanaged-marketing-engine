<?php
/**
 * Won leads, worked out (code only, no AI): every few hours the head of Follow-up looks for leads that have become customers.
 *   - Travel Malawi: the live listings on ulendomalawi.com (the public query the site's sitemap uses: approved listings only). A lead that matches a live listing
 *     by email, phone, company domain, or its name and town is Won: the Go live step of its progress is marked done (the "your listing is live" update waits
 *     for the owner's Send), and the listing's link is kept on the lead. A name that matches without anything else is a likely win for the owner to confirm.
 *   - Any business with a website: a lead we are talking to whose whole name is on one of our own pages (a client list, a portfolio) is a likely win.
 *   - The inbox: the words of a yes in their last message ("we have listed", "go ahead", "payment sent", "signed the agreement") make it a likely win.
 * A likely win shows on the lead (Yes, won / Not yet) and as a chip on its row; nothing is sent. Data: data/wins.json {brand: {at, listings, pages_at, pages}};
 * on the lead: live_listing {id, url, name, how, at}, win_hint {why, at, key}, win_no [keys the owner said Not yet to].
 */

const PM_WIN_LISTINGS = 'https://firestore.googleapis.com/v1/projects/promanaged-it/databases/ai-studio-20082453-79ed-4d1e-9926-73d4e8b86739/documents:runQuery'; // Travel-Malawi: firebase-applet-config.json
const PM_WIN_LISTING_URL = 'https://ulendomalawi.com/hotel/';

/** Words of a yes in their message: [regex, what it means]. */
const PM_WIN_YES = [
    'travel' => ['/\b(i|we)(\'ve| have)?\s+(just |now |already )?(listed|signed up|registered|submitted|created (an|my|our|the) account|added (my|our|the) (property|lodge|place|stay))\b|\b(it\'?s|it is|we\'?re|we are|i\'?m|i am) (now )?(live|listed)\b/i', 'they say they have listed or signed up'],
    'all' => ['/\b(go ahead|let\'?s (go|proceed|start)|please (proceed|start)|we (accept|agree)|accepted (your|the) (proposal|quote|offer)|payment (made|sent|done|is done)|(i|we) (have |already |just )?paid|deposit (paid|sent)|signed (the|your) (agreement|contract|proposal))\b/i', 'their message reads like a yes'],
];

function pm_win_state(string $brand): array
{
    return (array)(pm_load('wins', fn() => [])[$brand] ?? []) + ['at' => '', 'listings' => [], 'pages_at' => '', 'pages' => []];
}

/** Travel Malawi's live listings: [['id','name','town','emails','phones']], fetched at most every 3 hours. [] when the site cannot be reached. */
function pm_win_listings(): array
{
    $ch = curl_init(PM_WIN_LISTINGS);
    $fields = array_map(fn($f) => ['fieldPath' => $f], ['name', 'location', 'contactEmail', 'contactPhone', 'contactWhatsapp', 'ownerEmail', 'ownerPhone', 'managerEmail', 'managerPhone']);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['structuredQuery' => ['from' => [['collectionId' => 'hotels']], 'select' => ['fields' => $fields], 'limit' => 1000,
            'where' => ['fieldFilter' => ['field' => ['fieldPath' => 'status'], 'op' => 'EQUAL', 'value' => ['stringValue' => 'approved']]]]])]);
    function_exists('pm_curl_native_ca') && pm_curl_native_ca($ch);
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $rows = json_decode($raw, true);
    if ($code !== 200 || !is_array($rows)) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $d = (array)($r['document'] ?? []);
        $f = (array)($d['fields'] ?? []);
        $s = fn(string $k) => trim((string)($f[$k]['stringValue'] ?? ''));
        if (empty($d['name']) || $s('name') === '') {
            continue;
        }
        $out[] = ['id' => basename((string)$d['name']), 'name' => $s('name'), 'town' => $s('location'),
            'emails' => array_values(array_unique(array_filter(array_map('strtolower', [$s('contactEmail'), $s('ownerEmail'), $s('managerEmail')]), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)))),
            'phones' => array_values(array_unique(array_filter(array_map('pm_win_phone', [$s('contactPhone'), $s('contactWhatsapp'), $s('ownerPhone'), $s('managerPhone')]))))];
    }
    return $out;
}

/** A phone number's last nine digits ('' when too short): 0888 226 665, +265 888 226 665 and 265888226665 are one number. */
function pm_win_phone(string $p): string
{
    $d = (string)preg_replace('/\D+/', '', $p);
    return strlen($d) >= 9 ? substr($d, -9) : '';
}

/** How a lead matches a live listing: ['strong' => bool, 'how' => 'their email' | ...] or null. */
function pm_win_match(array $lead, array $x): ?array
{
    $emails = array_values(array_filter(array_map('strtolower', array_merge([(string)($lead['email'] ?? '')], (array)($lead['alt_emails'] ?? []))), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    if (array_intersect($emails, $x['emails'])) {
        return ['strong' => true, 'how' => 'their email'];
    }
    $phones = array_filter(array_map('pm_win_phone', [(string)($lead['phone'] ?? ''), (string)($lead['whatsapp'] ?? '')]));
    if ($phones && array_intersect($phones, $x['phones'])) {
        return ['strong' => true, 'how' => 'their phone number'];
    }
    $dom = fn(string $e) => function_exists('pm_mb_domain') ? pm_mb_domain($e) : '';
    $doms = array_filter(array_merge(array_map($dom, $emails), [$dom((string)($lead['website'] ?? ''))]));
    if ($doms && array_intersect($doms, array_filter(array_map($dom, $x['emails'])))) {
        return ['strong' => true, 'how' => 'their company\'s email domain'];
    }
    $name = trim((string)($lead['name'] ?? ''));
    if ($name === '' || !(pm_study_names_business($x['name'], $name) || pm_study_names_business($name, $x['name']))) {
        return null;
    }
    $city = mb_strtolower(trim((string)($lead['city'] ?? '')));
    $sameTown = $city !== '' && $city !== 'malawi' && str_contains(mb_strtolower($x['town']), $city);
    return ['strong' => $sameTown, 'how' => $sameTown ? 'its name and town' : 'its name'];
}

/** Have we been in a real conversation with the lead (they wrote, or a proposal went)? */
function pm_win_talking(array $l): bool
{
    return !empty($l['proposal_sent_at']) || (bool)array_filter((array)($l['thread'] ?? []), fn($m) => ($m['dir'] ?? '') === 'in');
}

/** Sets a likely win on the lead, unless the owner already said Not yet to the same reason. */
function pm_win_hint(array &$l, string $key, string $why): bool
{
    if (in_array($key, (array)($l['win_no'] ?? []), true) || (($l['win_hint']['key'] ?? '') === $key)) {
        return false;
    }
    $l['win_hint'] = ['why' => $why, 'at' => date('Y-m-d H:i'), 'key' => $key];
    pm_lead_note($l, 'Looks won: ' . $why . '. Confirm on the lead.');
    return true;
}

/** The check for one business. Returns ['won' => [names], 'likely' => [names]]. */
function pm_win_check(string $brand): array
{
    $res = ['won' => [], 'likely' => []];
    $st = pm_win_state($brand);
    $listings = [];
    if ($brand === 'travel') {
        $listings = pm_win_listings();
        $listings && $st['listings'] = $listings;
        $listings = $listings ?: (array)$st['listings'];
    }
    $pages = (array)$st['pages'];
    $site = trim((string)pm_brand_setting($brand, 'website', ''));
    if ($brand !== 'travel' && $site !== '' && (strtotime((string)$st['pages_at']) ?: 0) < time() - 43200 && function_exists('pm_study_pages')) { // our own pages, twice a day
        $pages = [];
        foreach ((array)(pm_study_pages($site, 8)['pages'] ?? []) as $p) {
            $pages[(string)$p['url']] = mb_substr((string)$p['text'], 0, 40000);
        }
        $st['pages_at'] = date('Y-m-d H:i');
        $st['pages'] = $pages;
    }
    $siteText = implode("\n", $pages);
    $wonLive = [];
    pm_update('leads', function (array $cur) use ($brand, $listings, $siteText, $site, &$res, &$wonLive) {
        foreach ($cur as $id => $l) {
            if (pm_brand_norm((string)($l['brand'] ?? 'promanaged')) !== $brand || in_array($l['status'] ?? '', ['lost', 'optout'], true) || !empty($l['listen'])) {
                continue;
            }
            $won = ($l['status'] ?? '') === 'won';
            // the lead is live on ulendomalawi.com
            foreach ($listings as $x) {
                if (!empty($l['live_listing']['id']) || !($m = pm_win_match($l, $x))) {
                    continue;
                }
                if ($m['strong']) {
                    $cur[$id]['live_listing'] = ['id' => $x['id'], 'url' => PM_WIN_LISTING_URL . rawurlencode($x['id']), 'name' => $x['name'], 'how' => $m['how'], 'at' => date('Y-m-d H:i')];
                    unset($cur[$id]['win_hint']);
                    pm_lead_note($cur[$id], 'Live on ulendomalawi.com as "' . $x['name'] . '" (matched by ' . $m['how'] . '): ' . PM_WIN_LISTING_URL . $x['id']);
                    $wonLive[] = (string)$id;
                    $res['won'][] = (string)$l['name'];
                } elseif (!$won && pm_win_hint($cur[$id], 'listing:' . $x['id'], 'a listing called "' . $x['name'] . '" (' . $x['town'] . ') is live on ulendomalawi.com')) {
                    $res['likely'][] = (string)$l['name'];
                }
                break;
            }
            if ($won || !empty($cur[$id]['live_listing']) || !empty($cur[$id]['win_hint'])) {
                continue;
            }
            // their whole name on one of our own pages, while we are talking to them
            $name = trim((string)($l['name'] ?? ''));
            if ($siteText !== '' && $name !== '' && pm_win_talking($l) && pm_study_names_business($siteText, $name)
                && pm_win_hint($cur[$id], 'site:' . md5($name), 'their name is on our website (' . preg_replace('#^https?://#', '', $site) . ')')) {
                $res['likely'][] = $name;
                continue;
            }
            // the words of a yes in their last message
            $last = null;
            foreach (array_reverse((array)($l['thread'] ?? [])) as $t) {
                if (($t['dir'] ?? '') === 'in') {
                    $last = $t;
                    break;
                }
            }
            if ($last && strtotime((string)$last['at']) > strtotime('-60 days')) {
                foreach ([PM_WIN_YES[$brand] ?? null, PM_WIN_YES['all']] as $y) {
                    if ($y && preg_match($y[0], (string)$last['text'], $mm) && pm_win_hint($cur[$id], 'said:' . md5((string)$last['at']), $y[1] . ' ("' . trim($mm[0]) . '", ' . substr((string)$last['at'], 0, 10) . ')')) {
                        $res['likely'][] = $name;
                        break;
                    }
                }
            }
        }
        return $cur;
    }, fn() => []);
    // live: the Go live step is done (makes it Won and writes the "your listing is live" update, which waits for the owner's Send)
    if ($wonLive && function_exists('pm_pg_mark')) {
        $told = []; // a lead we already told it is live (by hand, in the Sent folder): no second update
        foreach (function_exists('pm_mb_state') ? pm_mb_state($brand)['items'] : [] as $it) {
            if ($it['dir'] === 'out' && !empty($it['lead']) && preg_match('/\b(is|now) live\b|\bgood news\b/i', (string)$it['subject'])) {
                $told[(string)$it['lead']] = (string)$it['subject'];
            }
        }
        pm_update('leads', function (array $cur) use ($wonLive, $told) {
            foreach ($wonLive as $id) {
                if (!isset($cur[$id])) {
                    continue;
                }
                $steps = pm_pg_steps($cur[$id]);
                $live = null;
                foreach ($steps as $i => $s) {
                    if (pm_pg_is_live((string)$s[0])) {
                        $live = $i;
                        break;
                    }
                }
                $pg = (array)($cur[$id]['progress'] ?? []);
                if ($live === null || (isset($pg['at']) && ((int)$pg['at'] > $live || ((int)$pg['at'] === $live && ($pg['state'] ?? '') === 'done')))) {
                    $cur[$id]['status'] = in_array($cur[$id]['status'] ?? '', ['lost', 'optout'], true) ? $cur[$id]['status'] : 'won';
                    continue;
                }
                pm_pg_mark($cur[$id], $live, 'done', (string)($cur[$id]['live_listing']['url'] ?? ''));
                if (isset($told[$id])) {
                    unset($cur[$id]['progress']['msg']);
                    $n = count((array)$cur[$id]['progress']['log']);
                    $cur[$id]['progress']['log'][$n - 1]['sent'] = 'already told them by hand';
                    pm_lead_note($cur[$id], 'No "you are live" update to send: we already told them ("' . $told[$id] . '")');
                }
            }
            return $cur;
        }, fn() => []);
    }
    $st['at'] = date('Y-m-d H:i');
    pm_update('wins', function (array $all) use ($brand, $st) {
        $all[$brand] = $st;
        return $all;
    }, fn() => []);
    if ($res['won'] || $res['likely']) {
        $line = trim(($res['won'] ? 'Won, live on ulendomalawi.com: ' . implode(', ', array_slice($res['won'], 0, 4)) . '. ' : '') . ($res['likely'] ? 'Looks won, for you to confirm: ' . implode(', ', array_slice($res['likely'], 0, 4)) . '.' : ''));
        function_exists('pm_tb_post') && pm_tb_post($brand, 'followup', 'director', $line, 'wins:' . date('Y-m-d'));
        function_exists('pm_talk') && pm_talk($brand, 'followup', $line);
    }
    return $res;
}

/** Scheduler job: every 3 hours per business. */
function pm_job_wins(string $brand): string
{
    if ((strtotime(pm_win_state($brand)['at']) ?: 0) > time() - 10800) {
        return '';
    }
    $r = pm_win_check($brand);
    return $r['won'] || $r['likely'] ? 'won: ' . implode(', ', $r['won']) . ($r['likely'] ? '; likely: ' . implode(', ', $r['likely']) : '') : '';
}

/** The owner answers a likely win: Yes (Won) or Not yet (not asked again for the same reason). Returns the message. */
function pm_win_answer(array &$lead, bool $yes): string
{
    $h = (array)($lead['win_hint'] ?? []);
    if (!$h) {
        return 'Nothing to confirm.';
    }
    unset($lead['win_hint']);
    if (!$yes) {
        $lead['win_no'] = array_slice(array_merge((array)($lead['win_no'] ?? []), [(string)$h['key']]), -10);
        pm_lead_note($lead, 'Not won yet (' . $h['why'] . ')');
        return 'Noted: not won yet. ' . ($lead['name'] ?? 'The lead') . ' is not asked about again for this.';
    }
    $lead['status'] = 'won';
    pm_lead_note($lead, 'Won: ' . $h['why']);
    return ($lead['name'] ?? 'The lead') . ' is Won. Keep them posted under Their progress.';
}

/** A likely win on the lead's card: why, and the two answers. */
function pm_win_panel(array $l, callable $post): string
{
    $h = (array)($l['win_hint'] ?? []);
    $live = (array)($l['live_listing'] ?? []);
    if ($live) {
        return '<p class="hint my-1"><span class="pill hot">live</span> On ulendomalawi.com as <a href="' . pm_h((string)$live['url']) . '" target="_blank" rel="noopener noreferrer">' . pm_h((string)$live['name']) . '</a> · matched by ' . pm_h((string)$live['how']) . '</p>';
    }
    if (!$h || in_array($l['status'] ?? '', ['won', 'lost', 'optout'], true)) {
        return '';
    }
    return '<form method="post" class="replybox ok">' . $post('win_yes') . '<b>Looks won</b> <span class="hint">' . pm_h(ucfirst((string)$h['why'])) . '.</span>'
        . '<div class="btns"><button class="btn small primary">Yes, won</button><button class="btn small ghost" name="do" value="win_no">Not yet</button></div></form>';
}

/** The chip on the lead's row. */
function pm_win_chip(array $l): string
{
    if (!empty($l['live_listing']) && empty($l['progress'])) {
        return '<span class="pill hot" title="Live on ulendomalawi.com">live</span>';
    }
    return !empty($l['win_hint']) && !in_array($l['status'] ?? '', ['won', 'lost', 'optout'], true) ? '<span class="pill ok" title="' . pm_h((string)$l['win_hint']['why']) . '">looks won</span>' : '';
}
