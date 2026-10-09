<?php
/**
 * The growth engine for each business's Facebook Page:
 *  - People: everyone who reacted, commented or messaged, scored by buying signals; buyers go straight into the leads.
 *  - Playbook: who to engage today, when, on which channel and with which words (one AI call a day).
 *  - Clean-up: an AI editor that reviews every post and the profile and proposes keep / rewrite / delete.
 *  - Ads: AI-planned targeted campaigns, created PAUSED in Ads Manager (never spends money by itself).
 *  - Judge: AI moderation of what visitors put on the Page (comments, replies, visitor posts) with one-click suggestions.
 *  - Autopilot: deletes clear scams, hides junk, likes fans, posts replies (bold), captures buyers, refreshes the playbook.
 */
require_once __DIR__ . '/social_manage.php';
require_once __DIR__ . '/links.php';

/** The brand's website for ads and links ('' if none). */
function pm_brand_site(string $brand): string
{
    $s = pm_settings();
    return pm_link_cfg($brand)['url'] ?: pm_clean_url((string)(($brand === 'travel' ? ($s['travel']['website'] ?? '') : ($s['website'] ?? ''))));
}

function pm_social_nav(string $on): string
{
    $tabs = ['' => 'Plan', 'growth' => 'Growth', 'page' => 'Page', 'inbox' => 'Inbox', 'cleanup' => 'Clean-up', 'ads' => 'Ads', 'audit' => 'Audit', 'accounts' => 'Accounts &amp; branding'];
    $h = '<div class="filters subnav">';
    foreach ($tabs as $k => $l) {
        $h .= '<a href="?tab=social' . ($k !== '' ? '&view=' . $k : '') . '"' . ($k === $on ? ' class="on"' : '') . '>' . $l . '</a>';
    }
    return $h . '</div>';
}

/** The live Facebook Page as visitors see it, beside the cleaning tools. Facebook's own Page plugin: costs no API calls. */
function pm_fb_page_embed(string $brand, int $height = 1400): string
{
    $pg = pm_social_page($brand);
    $link = (string)($pg['link'] ?? '');
    if ($link === '') {
        $id = pm_social_cfg($brand)['page_id'];
        $link = $id !== '' ? 'https://www.facebook.com/' . $id : '';
    }
    if ($link === '') {
        return '';
    }
    $src = 'https://www.facebook.com/plugins/page.php?' . http_build_query(['href' => $link, 'tabs' => 'timeline', 'width' => 500, 'height' => $height, 'small_header' => 'false',
        'adapt_container_width' => 'true', 'hide_cover' => 'false', 'show_facepile' => 'true']);
    return '<aside class="wppage"><div class="wphead"><b>Your Page, live</b> <a class="btn small" href="' . pm_h($link) . '" target="_blank" rel="noopener noreferrer">Open on Facebook</a>'
        . ' <button type="button" class="btn small" data-reframe="1">Refresh</button></div>'
        . '<iframe class="fbplug" data-src="' . pm_h($src) . '" width="500" height="700" style="border:none" frameborder="0" allowfullscreen="true"'
        . ' allow="autoplay; clipboard-write; encrypted-media; picture-in-picture; web-share" title="' . pm_h((string)($pg['name'] ?? 'Facebook Page')) . ' on Facebook"></iframe>'
        . '<p class="hint">What visitors see right now. If it stays blank, your browser is blocking Facebook content: use Open on Facebook.</p></aside>';
}

/* ---------------- signals worked out in code (free) ---------------- */

/** Words that show someone wants to buy or book (English and Chichewa). */
function pm_buy_signal(string $t): bool
{
    // bare "can you", "contact" and "dm" are everyday words, not buying signals
    return (bool)preg_match('/\b(price|prices|how much|cost|quote|quotation|interested|available|availability|book|booking|order|buy|deliver|delivery|inbox|dm me|pm me|send me (the|your|a|some|details|prices|info)|whatsapp me|call me|need (a|an|one|some)|looking for|do you (have|sell|offer|do)|can you (help|quote|supply|install|build|set up|deliver|offer|do)|zingati|ndalama|mtengo|ndikufuna|ndingapeze|muli nazo)\b/i', $t);
}

/** Junk comments: scams, link spam, crypto and "earn money" offers. */
function pm_spam_signal(string $t): bool
{
    return (bool)preg_match('/(bitcoin|crypto|forex|binary option|invest(ment)? (plan|platform)|earn \$?\d+|profit of|recover (your|hacked)|hacked account|account (recovery|restored)|click (the|this) link|t\.me\/|bit\.ly|tinyurl|cutt\.ly|wa\.me\/\+?(?!265)\d|telegram|loan offer|sugar (mummy|daddy)|onlyfans|follow back|check (out )?my (page|profile)|f4f|sub4sub'
        . '|meta (support|business|team)|facebook (support|security|team)|page (will be|has been) (disabled|deleted|restricted|suspended|unpublished)|copyright (violation|infringement)|verify your (page|account)|community standards violation|appeal (here|now)'
        . '|you (have )?won|claim your (prize|reward|gift)|giveaway winner|lucky winner|congratulations,? you|free (iphone|followers|likes)|grow your (page|followers)|buy (followers|likes)|promote your page'
        . '|(whatsapp|call|text) (me )?(on )?\+(?!265)\d{6,}|\+(?!265)(1|44|234|233|91|92|254|27)\d{8,}|hot (girls|singles)|dating|hookup|xxx|porn)/iu', $t);
}

/** Everyone who touched the Page recently, scored. Reactions, comments, Messenger. */
function pm_fb_people(string $brand): array
{
    $c = pm_social_cfg($brand);
    $people = [];
    $add = function (string $id, string $name, string $kind, string $text, string $at, string $link, array $extra = []) use (&$people, $c) {
        if ($id === '' || $id === $c['page_id']) {
            return;
        }
        $p = $people[$id] ?? ['id' => $id, 'name' => $name, 'reacted' => 0, 'commented' => 0, 'messaged' => 0, 'buyer' => false, 'buy_hits' => 0, 'spam' => false, 'last' => '', 'said' => [], 'links' => [], 'post_ids' => [], 'unanswered' => 0];
        $p[$kind]++;
        if ($text !== '') {
            $hit = pm_buy_signal($text);
            $p['buyer'] = $p['buyer'] || $hit;
            $p['buy_hits'] += $hit ? 1 : 0;
            $p['spam'] = $p['spam'] || pm_spam_signal($text);
            if (count($p['said']) < 3) {
                $p['said'][] = mb_substr($text, 0, 160);
            }
        }
        if ($at > $p['last']) {
            $p['last'] = $at;
        }
        if ($link !== '' && !in_array($link, $p['links'], true)) {
            $p['links'][] = $link;
        }
        $p['unanswered'] += (int)($extra['unanswered'] ?? 0);
        if (!empty($extra['post']) && !in_array($extra['post'], $p['post_ids'], true)) {
            $p['post_ids'][] = $extra['post']; // which of our posts they reacted to
        }
        $people[$id] = $p;
    };
    foreach (pm_fb_feed($brand)['items'] as $post) { // one shared, cached call (who reacted is hidden by Facebook anyway)
        if ($post['comments'] > 0) {
            foreach ($post['comment_list'] as $cm) {
                if (!$cm['ours']) {
                    $add((string)($cm['from_id'] ?? md5($cm['from'])), $cm['from'], 'commented', $cm['message'], $cm['at'], $post['url'], ['unanswered' => $cm['answered'] ? 0 : 1, 'post' => (string)$post['id']]);
                }
            }
        }
    }
    foreach (pm_fb_inbox($brand)['threads'] as $t) {
        $last = end($t['messages']) ?: ['text' => '', 'at' => $t['updated']];
        foreach ($t['messages'] as $m) {
            if (!$m['ours']) {
                $add($t['psid'], $t['who'], 'messaged', $m['text'], $m['at'], '', ['unanswered' => 0]);
            }
        }
        if ($t['waiting'] && isset($people[$t['psid']])) {
            $people[$t['psid']]['unanswered']++;
            $people[$t['psid']]['window'] = $t['window'];
        }
    }
    foreach ($people as &$p) {
        $p['score'] = ($p['spam'] ? -50 : 0) + ($p['buyer'] ? 40 : 0) + 15 * $p['messaged'] + 8 * $p['commented'] + 2 * $p['reacted'] + 10 * $p['unanswered']
            + (strtotime($p['last']) > time() - 7 * 86400 ? 10 : 0);
    }
    unset($p);
    uasort($people, fn($a, $b) => $b['score'] <=> $a['score']);
    return $people;
}

/**
 * People who clearly asked about buying or booking become leads (status "qualified", score 60: they came to us but nobody has spoken to them yet).
 * A person qualifies on the judge's verdict "buyer" or at least 2 buying signals. Dedupe by Facebook id and by name. The lead records which of our posts it came from.
 * Returns how many were added.
 */
function pm_fb_capture_buyers(string $brand, ?array $people = null): int
{
    $people ??= pm_fb_people($brand);
    $lb = $brand === 'travel' ? 'travel' : 'promanaged';
    $judgedBuyers = [];
    foreach ((array)(pm_load('fb_judged', fn() => [])[$brand] ?? []) as $k) {
        if (($k['verdict'] ?? '') === 'buyer') {
            $judgedBuyers[mb_strtolower(trim((string)($k['from'] ?? '')))] = true;
        }
    }
    $ours = [];
    foreach (pm_social_posts() as $sp) {
        if (($sp['fb_id'] ?? '') !== '') {
            $ours[$sp['fb_id']] = $sp['id'];
        }
    }
    $leads = pm_leads();
    $known = [];
    foreach ($leads as $l) {
        if (!empty($l['fb_from_id'])) {
            $known[$l['fb_from_id']] = true;
        }
    }
    $n = 0;
    foreach ($people as $p) {
        if (!$p['buyer'] || $p['spam'] || $p['name'] === '') {
            continue;
        }
        if (empty($judgedBuyers[mb_strtolower(trim($p['name']))]) && (int)($p['buy_hits'] ?? 0) < 2) {
            continue; // one vague word is not a buyer
        }
        $id = pm_lead_id($p['name'], 'Facebook', $lb);
        if (isset($leads[$id]) || isset($known[(string)$p['id']])) {
            continue;
        }
        $fp = (string)($p['post_ids'][0] ?? '');
        $leads[$id] = ['id' => $id, 'brand' => $lb, 'name' => $p['name'], 'type' => 'Facebook enquiry', 'city' => 'Facebook', 'address' => '', 'website' => '', 'phone' => '', 'email' => '', 'whatsapp' => '',
            'contact' => $p['name'], 'contact_title' => '', 'facebook' => '', 'fb_profile' => (string)($p['profile'] ?? ''), 'fb_from_id' => (string)$p['id'], 'source_post' => $ours[$fp] ?? $fp,
            'evidence' => array_map(fn($s) => 'Said on our Facebook Page: "' . $s . '"', $p['said']),
            'need_signals' => ['Asked us on Facebook'], 'score' => 60, 'status' => 'qualified', 'source' => 'facebook',
            'notes' => [['at' => date('Y-m-d H:i'), 'by' => 'Social agent', 'text' => 'Showed buying interest on our Facebook Page' . ($p['messaged'] ? ' (Messenger)' : ' (comment)') . '. Answer them there first, then move to WhatsApp or a call.']],
            'drafts' => [], 'sent' => [], 'followups' => 0, 'created' => date('Y-m-d H:i'), 'updated' => date('Y-m-d H:i')];
        $n++;
    }
    if ($n) {
        pm_leads_save($leads);
        pm_agent_log('Social', "$n Facebook buyer(s) added to the leads");
    }
    return $n;
}

/** When this Page's audience engages, from its own data; Malawi working-day habits until there is enough data. */
function pm_fb_best_times(string $brand): array
{
    $a = pm_load('page_audit', fn() => [])[$brand]['data']['posting']['by_hour'] ?? [];
    $a = array_filter((array)$a, fn($v, $h) => $h >= 6 && $h <= 21, ARRAY_FILTER_USE_BOTH);
    arsort($a);
    $hours = count($a) >= 4 ? array_slice(array_keys($a), 0, 3) : [];
    return ['hours' => $hours, 'note' => $hours ? 'From this Page\'s own results' : 'Not enough data yet: using Malawi habits (07:00 to 08:30 before work, 12:30 to 13:30 lunch, 18:30 to 20:30 evening; Tuesday to Thursday strongest for businesses, Friday to Sunday for travel)'];
}

/* ---------------- the daily engagement playbook: who, when, where, how ---------------- */

function pm_agent_engage_playbook(string $brand, bool $force = false): array
{
    pm_brand_set($brand);
    $people = pm_fb_people($brand);
    pm_fb_capture_buyers($brand, $people);
    $crowd = array_slice(array_values(array_filter($people, fn($p) => !$p['spam'])), 0, 10);
    $lb = $brand === 'travel' ? 'travel' : 'promanaged';
    $hot = [];
    foreach (pm_leads() as $l) {
        if (($l['brand'] ?? 'promanaged') !== $lb || in_array($l['status'], ['won', 'lost', 'optout', 'new'], true) || ($l['source'] ?? '') === 'facebook') {
            continue;
        }
        $hot[] = ['lead_id' => $l['id'], 'name' => $l['name'], 'type' => $l['type'], 'city' => $l['city'], 'status' => $l['status'], 'facebook' => $l['facebook'] ?? '', 'whatsapp' => ($l['whatsapp'] ?? '') ?: ($l['phone'] ?? ''),
            'contact' => $l['contact'] ?? '', 'last' => $l['updated'] ?? '',
            'known_about_them' => array_slice(array_values(array_filter(array_merge((array)($l['research']['hooks'] ?? []), array_slice((array)($l['evidence'] ?? []), 0, 2), array_slice((array)($l['social_gaps'] ?? []), 0, 1)))), 0, 3),
            'stage' => ['qualified' => 'first contact', 'drafted' => 'first contact', 'contacted' => 'follow-up: we wrote before and had no answer', 'replied' => 'they answered us: move to a call or meeting', 'proposal' => 'proposal sent: help them decide'][$l['status']] ?? $l['status']];
    }
    $rank = ['replied' => 0, 'proposal' => 1, 'contacted' => 2, 'drafted' => 3, 'qualified' => 4];
    usort($hot, fn($a, $b) => (($rank[$a['status']] ?? 5) <=> ($rank[$b['status']] ?? 5)) ?: strcmp($a['last'], $b['last']));
    $hot = array_slice($hot, 0, 8);
    $times = pm_fb_best_times($brand);
    $posts = pm_fb_posts($brand, 5)['posts'];
    // same people, same leads, same stage as last time: reuse the playbook (no AI call)
    $sig = md5(json_encode([array_map(fn($p) => [$p['id'], $p['commented'], $p['messaged'], $p['unanswered']], $crowd), array_map(fn($h) => [$h['lead_id'], $h['status'], $h['last']], $hot)]));
    $prev = pm_load('engage_playbook', fn() => [])[$brand] ?? null;
    if (!$force && $prev && ($prev['sig'] ?? '') === $sig && strtotime((string)$prev['at']) > time() - 3 * 86400) {
        $prev['day'] = date('Y-m-d');
        $all = pm_load('engage_playbook', fn() => []);
        $all[$brand] = $prev;
        pm_save('engage_playbook', $all);
        return $prev;
    }
    if (!$crowd && !$hot) {
        throw new RuntimeException('Nobody to engage yet: no comments, messages or warm leads. Run the agents or post first.');
    }
    $system = pm_agents_company_brief('tiny') . "\nYou are the business's sales-focused community manager. Today is " . date('l j F Y') . ". Build TODAY's engagement playbook that moves people closer to buying, "
        . "using only the people and leads given. For each task decide: who, why them now (their signal), when today (a clock time in Malawi time), channel "
        . "(reply_comment, messenger, whatsapp, call, comment_on_their_page, email), the exact words to use (short, human, specific to what they said or do, one easy question, no prices, no pressure, never promise times or results), and the goal of the step. "
        . "How the words must read: like a real person from a small local business, not an advert. Greet by first name when 'contact' is a person's name, otherwise 'Hello'. English unless the person wrote to us in Chichewa. "
        . "The first sentence is about THEM, built on one item from 'known_about_them' (or what they said on our Page); never open with what we do. At most one sentence about how we could help, phrased as a question or an offer to show, never a feature list. "
        . "End with one easy question. WhatsApp and Messenger 25 to 50 words; sign off with '" . pm_sender_name() . "'. Match the 'stage': a follow-up refers lightly to our earlier message; after a proposal, offer to answer questions or walk them through it. "
        . "Order by money: buyers and unanswered people first, then replied and proposal-stage leads, then follow-ups, then first contacts. Max 10 tasks. "
        . "Messenger is only possible if 'window' is true. Commenting on a lead's own Facebook page must add value (praise something real, a useful tip) and never sell. "
        . "Also give up to 3 'page_moves' for today (e.g. which post type to publish at which time, pin a post, a story, a live) and one line of 'focus'. Reply JSON only.";
    $user = json_encode(['best_times' => $times, 'our_last_posts' => array_map(fn($p) => ['when' => $p['at'], 'text' => mb_substr($p['message'], 0, 100), 'engagement' => $p['reactions'] + $p['comments'] + $p['shares']], $posts),
            'people_on_our_page' => array_map(fn($p) => ['ref' => 'p:' . $p['id'], 'name' => $p['name'], 'reacted' => $p['reacted'], 'commented' => $p['commented'], 'messaged' => $p['messaged'], 'buying_words' => $p['buyer'],
                'waiting_for_our_answer' => $p['unanswered'], 'window' => $p['window'] ?? false, 'said' => $p['said'], 'last' => $p['last']], $crowd),
            'warm_leads' => array_map(fn($h) => ['ref' => 'l:' . $h['lead_id']] + $h, $hot)], JSON_UNESCAPED_UNICODE)
        . "\nJSON: {\"focus\":\"\",\"tasks\":[{\"ref\":\"p:.. or l:..\",\"who\":\"\",\"why\":\"\",\"when\":\"HH:MM\",\"channel\":\"\",\"message\":\"\",\"goal\":\"\"}],\"page_moves\":[{\"when\":\"\",\"what\":\"\"}]}";
    $out = pm_agent_json(pm_claude($system, $user, false, 2500, 'write'));
    if (!is_array($out) || !isset($out['tasks'])) {
        throw new RuntimeException('The playbook did not come back complete. Try again.');
    }
    $tasks = [];
    foreach (array_slice((array)$out['tasks'], 0, 10) as $i => $t) {
        $ref = (string)($t['ref'] ?? '');
        $link = '';
        if (str_starts_with($ref, 'p:') && isset($people[substr($ref, 2)])) {
            $link = $people[substr($ref, 2)]['links'][0] ?? '';
        } elseif (str_starts_with($ref, 'l:')) {
            $link = '?tab=agents&rl=' . substr($ref, 2) . '#lead-' . substr($ref, 2);
        }
        $tasks[] = ['n' => $i, 'ref' => $ref, 'who' => (string)($t['who'] ?? ''), 'why' => (string)($t['why'] ?? ''), 'when' => (string)($t['when'] ?? ''), 'channel' => (string)($t['channel'] ?? ''),
            'message' => (string)($t['message'] ?? ''), 'goal' => (string)($t['goal'] ?? ''), 'link' => $link, 'done' => false];
    }
    usort($tasks, fn($a, $b) => strcmp($a['when'], $b['when']));
    $pb = ['at' => date('Y-m-d H:i'), 'day' => date('Y-m-d'), 'sig' => $sig, 'focus' => (string)($out['focus'] ?? ''), 'tasks' => $tasks, 'moves' => array_slice((array)($out['page_moves'] ?? []), 0, 3), 'times' => $times];
    $all = pm_load('engage_playbook', fn() => []);
    $all[$brand] = $pb;
    pm_save('engage_playbook', $all);
    pm_agent_log('Social', 'Engagement playbook ready: ' . count($tasks) . ' task(s)');
    return $pb;
}

/* ---------------- clean-up: the AI editor for the Page ---------------- */

function pm_agent_page_cleanup(string $brand): array
{
    pm_brand_set($brand);
    $posts = pm_fb_posts($brand, 50)['posts'];
    $data = pm_fb_audit_data($brand)['page'];
    $system = pm_agents_company_brief('tiny') . "\nYou are cleaning up this business's Facebook Page so a business owner who visits it trusts us in 5 seconds. Review every post. "
        . "For each decide: keep (on-brand and useful), rewrite (good idea but weak text: give the full new text, honest, no invented facts or prices, ends with an easy call to action), or delete (off-brand, outdated, duplicate, embarrassing, or crowds the page with near-identical posts). "
        . "Be decisive but not destructive: delete only what hurts how the business looks; a Page needs some posts. Then rewrite the Page's 'about' (max 100 characters) and 'description' (max 250 characters) from the facts. Reply JSON only.";
    $user = json_encode(['page' => $data, 'posts' => array_map(fn($p) => ['id' => $p['id'], 'when' => $p['at'], 'type' => $p['picture'] !== '' ? 'picture' : 'text', 'text' => mb_substr($p['message'], 0, 400),
            'reactions' => $p['reactions'], 'comments' => $p['comments'], 'shares' => $p['shares']], $posts)], JSON_UNESCAPED_UNICODE)
        . "\nJSON: {\"summary\":\"\",\"posts\":[{\"id\":\"\",\"action\":\"keep|rewrite|delete\",\"reason\":\"\",\"new_text\":\"\"}],\"about\":\"\",\"description\":\"\"}";
    $out = pm_agent_json(pm_claude($system, $user, false, 4000, 'write'));
    if (!is_array($out) || !isset($out['posts'])) {
        throw new RuntimeException('The clean-up review did not come back complete. Try again.');
    }
    $byId = array_column($posts, null, 'id');
    $items = [];
    foreach ((array)$out['posts'] as $p) {
        $id = (string)($p['id'] ?? '');
        if (!isset($byId[$id])) {
            continue;
        }
        $items[$id] = ['id' => $id, 'action' => in_array($p['action'] ?? '', ['keep', 'rewrite', 'delete'], true) ? $p['action'] : 'keep', 'reason' => (string)($p['reason'] ?? ''),
            'new_text' => (string)($p['new_text'] ?? ''), 'old_text' => $byId[$id]['message'], 'picture' => $byId[$id]['picture'], 'at' => $byId[$id]['at'], 'url' => $byId[$id]['url'], 'state' => ''];
    }
    $res = ['at' => date('Y-m-d H:i'), 'summary' => (string)($out['summary'] ?? ''), 'items' => $items, 'about' => mb_substr((string)($out['about'] ?? ''), 0, 100), 'description' => mb_substr((string)($out['description'] ?? ''), 0, 255),
        'old_about' => (string)$data['about'], 'old_description' => (string)$data['description']];
    $all = pm_load('page_cleanup', fn() => []);
    $all[$brand] = $res;
    pm_save('page_cleanup', $all);
    return $res;
}

/** Applies the ticked clean-up items. Returns [done, failed, messages]. */
function pm_page_cleanup_apply(string $brand, array $ids, array $texts): array
{
    $all = pm_load('page_cleanup', fn() => []);
    $cl = $all[$brand] ?? ['items' => []];
    $done = 0;
    $fail = [];
    foreach ($ids as $id) {
        $it = $cl['items'][$id] ?? null;
        if (!$it || $it['state'] !== '' || $it['action'] === 'keep') {
            continue;
        }
        [$ok, $m] = $it['action'] === 'delete' ? pm_fb_action($brand, 'delete', $id) : pm_fb_action($brand, 'edit', $id, trim((string)($texts[$id] ?? $it['new_text'])));
        if ($ok) {
            $cl['items'][$id]['state'] = $it['action'] === 'delete' ? 'deleted' : 'rewritten';
            $done++;
        } else {
            $fail[] = $m;
        }
    }
    $all[$brand] = $cl;
    pm_save('page_cleanup', $all);
    pm_agent_log('Social', "Page clean-up: $done change(s) applied" . ($fail ? ', ' . count($fail) . ' failed' : ''));
    return [$done, $fail];
}

/** Writes the Page's about/description. Facebook allows this only for some Pages; the error is returned as it is. */
function pm_page_profile_apply(string $brand, string $about, string $description): array
{
    $c = pm_social_cfg($brand);
    $p = array_filter(['about' => trim($about), 'description' => trim($description)], fn($v) => $v !== '');
    [$ok, $d] = pm_graph('POST', $c['page_id'], $p, $c['token']);
    return $ok ? [true, 'Page profile updated.'] : [false, 'Facebook would not take it (' . $d . '). Copy the text and paste it in Facebook: Page > Edit details.'];
}

/* ---------------- targeted ads (created PAUSED: you switch them on in Ads Manager) ---------------- */

function pm_ads_cfg(string $brand): array
{
    $e = pm_env();
    $p = $brand === 'travel' ? 'TM_' : '';
    $acct = preg_replace('/\D/', '', (string)($e[$p . 'FB_AD_ACCOUNT_ID'] ?? ''));
    $tok = trim((string)($e['FB_USER_TOKEN'] ?? ''));
    return ['account' => $acct, 'token' => $tok, 'ready' => $acct !== '' && $tok !== ''];
}

function pm_ads_accounts(): array
{
    $tok = trim((string)(pm_env()['FB_USER_TOKEN'] ?? ''));
    if ($tok === '') {
        return [false, 'No ads connection yet.'];
    }
    [$ok, $d] = pm_graph('GET', 'me/adaccounts', ['fields' => 'account_id,name,currency,account_status', 'limit' => 25], $tok);
    return $ok ? [true, (array)($d['data'] ?? [])] : [false, $d];
}

function pm_ads_account_info(string $brand): array
{
    $a = pm_ads_cfg($brand);
    if (!$a['ready']) {
        return [];
    }
    [$ok, $d] = pm_graph('GET', 'act_' . $a['account'], ['fields' => 'name,currency,account_status,amount_spent,balance'], $a['token']);
    return $ok ? $d : ['error' => $d];
}

/** Facebook counts most currencies in cents. */
function pm_ads_minor(float $amount, string $cur): int
{
    return in_array(strtoupper($cur), ['JPY', 'KRW', 'CLP', 'COP', 'CRC', 'HUF', 'ISK', 'IDR', 'PYG', 'TWD', 'VND'], true) ? (int)round($amount) : (int)round($amount * 100);
}

function pm_agent_ads_plan(string $brand): array
{
    pm_brand_set($brand);
    $info = pm_ads_account_info($brand);
    $cur = (string)($info['currency'] ?? 'USD');
    $posts = pm_fb_posts($brand, 25)['posts'];
    usort($posts, fn($a, $b) => ($b['reactions'] + 2 * $b['comments'] + 3 * $b['shares']) <=> ($a['reactions'] + 2 * $a['comments'] + 3 * $a['shares']));
    $s = pm_settings();
    $system = pm_agents_company_brief('tiny') . "\nYou are a performance marketer planning small, careful Facebook and Instagram ad campaigns in Malawi for a small owner-run business with a tight budget. "
        . "Plan up to 3 campaigns that bring real enquiries (messages or WhatsApp) from the right buyers, not likes. For each: a name, the goal (messages, traffic, engagement), "
        . "whether to boost one of the given posts (give its id) or run a new ad (primary text max 125 characters, headline max 40, no prices, honest, no invented claims), "
        . "the audience (Malawi cities, age range, up to 5 interest keywords Facebook knows, e.g. 'Small business', 'Hotel', 'Entrepreneurship', 'Travel'), daily budget in $cur (small: test first), number of days (3 to 14), why this will work, "
        . "and what to expect honestly (say results are unknown until tested; no guarantees). Also give 3 testing rules (when to stop, scale or change an ad). Reply JSON only.";
    $user = json_encode(['brand' => $s['company_name'], 'website' => pm_brand_site($brand), 'currency' => $cur,
            'best_posts' => array_map(fn($p) => ['id' => $p['id'], 'text' => mb_substr($p['message'], 0, 200), 'picture' => $p['picture'] !== '', 'reactions' => $p['reactions'], 'comments' => $p['comments'], 'shares' => $p['shares']], array_slice($posts, 0, 6)),
            'audit' => pm_audit_learnings($brand)], JSON_UNESCAPED_UNICODE)
        . "\nJSON: {\"campaigns\":[{\"name\":\"\",\"goal\":\"messages|traffic|engagement\",\"boost_post_id\":\"\",\"primary_text\":\"\",\"headline\":\"\",\"cities\":[\"Lilongwe\"],\"age_min\":25,\"age_max\":55,\"interests\":[],\"daily_budget\":0,\"days\":7,\"why\":\"\",\"expect\":\"\"}],\"rules\":[]}";
    $out = pm_agent_json(pm_claude($system, $user, false, 2500, 'write'));
    if (!is_array($out) || !isset($out['campaigns'])) {
        throw new RuntimeException('The ads plan did not come back complete. Try again.');
    }
    $plan = ['at' => date('Y-m-d H:i'), 'currency' => $cur, 'campaigns' => array_map(fn($c) => (array)$c + ['state' => '', 'ids' => []], array_slice((array)$out['campaigns'], 0, 3)), 'rules' => array_slice((array)($out['rules'] ?? []), 0, 3)];
    $all = pm_load('ads_plan', fn() => []);
    $all[$brand] = $plan;
    pm_save('ads_plan', $all);
    return $plan;
}

/** Creates one planned campaign in Ads Manager, everything PAUSED. Returns [ok, message]. */
function pm_ads_create(string $brand, int $i): array
{
    $a = pm_ads_cfg($brand);
    $fb = pm_social_cfg($brand);
    $all = pm_load('ads_plan', fn() => []);
    $c = $all[$brand]['campaigns'][$i] ?? null;
    if (!$a['ready'] || !$c) {
        return [false, 'Connect an ad account first.'];
    }
    if (($c['state'] ?? '') === 'created') {
        return [false, 'Already created in Ads Manager.'];
    }
    $act = 'act_' . $a['account'];
    $cur = (string)(pm_ads_account_info($brand)['currency'] ?? $all[$brand]['currency'] ?? 'USD');
    // audience: Malawi cities and interests looked up by name
    $geo = ['countries' => ['MW']];
    $cities = [];
    foreach (array_slice((array)($c['cities'] ?? []), 0, 5) as $city) {
        [$ok, $d] = pm_graph('GET', 'search', ['type' => 'adgeolocation', 'location_types' => json_encode(['city']), 'country_code' => 'MW', 'q' => (string)$city, 'limit' => 1], $a['token']);
        if ($ok && !empty($d['data'][0]['key'])) {
            $cities[] = ['key' => $d['data'][0]['key'], 'radius' => 40, 'distance_unit' => 'kilometer'];
        }
    }
    if ($cities) {
        $geo = ['cities' => $cities];
    }
    $interests = [];
    foreach (array_slice((array)($c['interests'] ?? []), 0, 5) as $kw) {
        [$ok, $d] = pm_graph('GET', 'search', ['type' => 'adinterest', 'q' => (string)$kw, 'limit' => 1], $a['token']);
        if ($ok && !empty($d['data'][0]['id'])) {
            $interests[] = ['id' => $d['data'][0]['id'], 'name' => $d['data'][0]['name']];
        }
    }
    $targeting = ['geo_locations' => $geo, 'age_min' => max(18, (int)($c['age_min'] ?? 25)), 'age_max' => min(65, (int)($c['age_max'] ?? 55)), 'publisher_platforms' => ['facebook', 'instagram']];
    if ($interests) {
        $targeting['flexible_spec'] = [['interests' => $interests]];
    }
    $boost = preg_match('/^\d+_\d+$/', (string)($c['boost_post_id'] ?? '')) ? (string)$c['boost_post_id'] : '';
    $goal = (string)($c['goal'] ?? 'engagement');
    $link = pm_brand_site($brand);
    $wa = preg_replace('/\D/', '', (string)(pm_settings()['phone'] ?? ''));
    if ($goal === 'traffic' && $link === '') {
        $goal = 'messages';
    }
    [$objective, $optimize, $dest] = match ($goal) {
        'traffic' => ['OUTCOME_TRAFFIC', 'LINK_CLICKS', 'WEBSITE'],
        'messages' => ['OUTCOME_ENGAGEMENT', 'CONVERSATIONS', 'MESSENGER'],
        default => ['OUTCOME_ENGAGEMENT', 'POST_ENGAGEMENT', 'ON_POST'],
    };
    if ($dest === 'ON_POST' && $boost === '') {
        [$objective, $optimize, $dest] = ['OUTCOME_ENGAGEMENT', 'CONVERSATIONS', 'MESSENGER'];
    }
    $ids = (array)($c['ids'] ?? []);
    $step = function (string $what, array $r) use (&$all, $brand, $i, &$ids) {
        $all[$brand]['campaigns'][$i]['ids'] = $ids;
        $all[$brand]['campaigns'][$i]['error'] = $r[0] ? '' : "$what: " . $r[1];
        pm_save('ads_plan', $all);
        return $r;
    };
    if (empty($ids['campaign'])) {
        $r = pm_graph('POST', "$act/campaigns", ['name' => 'AI · ' . $c['name'], 'objective' => $objective, 'status' => 'PAUSED', 'special_ad_categories' => json_encode([]), 'is_adset_budget_sharing_enabled' => 'false'], $a['token']);
        if (!$r[0]) {
            return $step('Campaign', [false, $r[1]]);
        }
        $ids['campaign'] = $r[1]['id'];
    }
    if (empty($ids['adset'])) {
        $p = ['name' => 'AI · ' . $c['name'] . ' · audience', 'campaign_id' => $ids['campaign'], 'daily_budget' => pm_ads_minor(max(1, (float)($c['daily_budget'] ?? 1)), $cur), 'billing_event' => 'IMPRESSIONS',
            'optimization_goal' => $optimize, 'destination_type' => $dest, 'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP', 'targeting' => json_encode($targeting), 'status' => 'PAUSED',
            'start_time' => date('c', strtotime('+1 hour')), 'end_time' => date('c', strtotime('+' . max(3, min(30, (int)($c['days'] ?? 7))) . ' days'))];
        if ($dest === 'MESSENGER') {
            $p['promoted_object'] = json_encode(['page_id' => $fb['page_id']]);
        }
        $r = pm_graph('POST', "$act/adsets", $p, $a['token']);
        if (!$r[0]) {
            return $step('Ad set', [false, $r[1]]);
        }
        $ids['adset'] = $r[1]['id'];
    }
    if (empty($ids['creative'])) {
        if ($boost !== '') {
            $spec = ['name' => 'AI · ' . $c['name'], 'object_story_id' => $boost];
        } else {
            $ld = ['message' => (string)($c['primary_text'] ?? ''), 'name' => (string)($c['headline'] ?? ''), 'link' => $link ?: 'https://www.facebook.com/' . $fb['page_id']];
            $ld['call_to_action'] = $dest === 'MESSENGER' ? ['type' => 'MESSAGE_PAGE', 'value' => ['app_destination' => 'MESSENGER']] : ['type' => 'LEARN_MORE', 'value' => ['link' => $ld['link']]];
            $hero = pm_hero_path($brand) ?: pm_logo_path();
            if ($hero !== '' && is_file($hero)) {
                [$okI, $dI] = pm_graph('POST', "$act/adimages", [], $a['token'], ['filename' => $hero]);
                if ($okI) {
                    $img = reset($dI['images']);
                    $ld['image_hash'] = $img['hash'] ?? '';
                }
            }
            $spec = ['name' => 'AI · ' . $c['name'], 'object_story_spec' => json_encode(['page_id' => $fb['page_id'], 'link_data' => $ld])];
        }
        $r = pm_graph('POST', "$act/adcreatives", $spec, $a['token']);
        if (!$r[0]) {
            return $step('Ad picture and text', [false, $r[1]]);
        }
        $ids['creative'] = $r[1]['id'];
    }
    if (empty($ids['ad'])) {
        $r = pm_graph('POST', "$act/ads", ['name' => 'AI · ' . $c['name'], 'adset_id' => $ids['adset'], 'creative' => json_encode(['creative_id' => $ids['creative']]), 'status' => 'PAUSED'], $a['token']);
        if (!$r[0]) {
            return $step('Ad', [false, $r[1]]);
        }
        $ids['ad'] = $r[1]['id'];
    }
    $all[$brand]['campaigns'][$i]['state'] = 'created';
    $step('', [true, '']);
    pm_agent_log('Social', 'Ad campaign created PAUSED in Ads Manager: ' . $c['name']);
    return [true, 'Created in Ads Manager, paused. Check it there and press the switch to start it.'];
}

/* ---------------- autopilot ---------------- */

function pm_autopilot_level(string $brand): string
{
    $l = (string)(pm_social_settings($brand)['autopilot'] ?? 'safe');
    return in_array($l, ['off', 'safe', 'bold'], true) ? $l : 'safe';
}

/** AI reply drafts the autopilot prepared, by comment or thread id. */
function pm_fb_drafts(): array
{
    return pm_load('fb_drafts', fn() => []);
}

/* ---------------- what other people put on our Page: judged, then one-click suggestions ---------------- */

/** Everything visitors put on the Page: comments and replies on our recent posts, and posts by others on the Page. */
function pm_fb_inbound(string $brand): array
{
    $items = [];
    foreach (pm_fb_feed($brand)['items'] as $post) {
        if (!$post['ours']) {
            $items[$post['id']] = ['id' => $post['id'], 'kind' => 'visitor_post', 'from' => $post['from'] ?: 'Someone', 'text' => $post['message'] ?: '(picture or link)', 'at' => $post['at'],
                'hidden' => $post['hidden'], 'liked' => false, 'answered' => false, 'post' => '', 'link' => $post['url']];
        }
        foreach ($post['comment_list'] as $cm) {
            if (!$cm['ours']) {
                $items[$cm['id']] = ['id' => $cm['id'], 'kind' => 'comment', 'from' => $cm['from'], 'text' => $cm['message'], 'at' => $cm['at'], 'hidden' => $cm['hidden'], 'liked' => $cm['liked'],
                    'answered' => $cm['answered'], 'post' => mb_substr($post['message'], 0, 160), 'link' => $post['url']];
            }
            foreach ($cm['replies'] as $r) { // junk often hides in the replies
                if (!$r['ours']) {
                    $items[$r['id']] = ['id' => $r['id'], 'kind' => 'reply', 'from' => $r['from'], 'text' => $r['message'], 'at' => $r['at'], 'hidden' => false, 'liked' => false,
                        'answered' => true, 'post' => mb_substr($post['message'], 0, 160), 'link' => $post['url']];
                }
            }
        }
    }
    $c = pm_social_cfg($brand);
    [$ok, $d] = pm_graph('GET', $c['page_id'] . '/ratings', ['limit' => 25, 'fields' => 'open_graph_story{id},reviewer,review_text,recommendation_type,created_time'], $c['token']);
    foreach ($ok ? (array)($d['data'] ?? []) : [] as $r) {
        $rid = (string)($r['open_graph_story']['id'] ?? '');
        if ($rid !== '') {
            $items[$rid] = ['id' => $rid, 'kind' => 'review', 'from' => (string)($r['reviewer']['name'] ?? 'Someone'), 'text' => (string)($r['review_text'] ?? ''), 'at' => (string)($r['created_time'] ?? ''),
                'hidden' => false, 'liked' => false, 'answered' => false, 'post' => ($r['recommendation_type'] ?? '') === 'negative' ? 'does not recommend us' : 'recommends us', 'link' => ''];
        }
    }
    return $items;
}

/** Checks the Page for new junk when someone opens the social screens, at most every 30 minutes (Facebook data is cached; the AI only sees new items). */
function pm_fb_judge_if_stale(string $brand): void
{
    $st = pm_load('autopilot', fn() => []);
    if (time() - (int)($st[$brand]['judged'] ?? 0) < 1800 || !pm_social_cfg($brand)['ready']) {
        return;
    }
    $st[$brand]['judged'] = time();
    pm_save('autopilot', $st);
    try {
        pm_agent_judge_safe($brand);
    } catch (Throwable) {
    }
}

function pm_agent_judge_safe(string $brand): void
{
    $g = pm_graph_guard();
    if ((int)$g['until'] <= time() && (int)$g['pct'] < 75) {
        pm_agent_junk_judge($brand);
    }
}

/**
 * The AI judge for visitors' content. Code catches the obvious (scams, emoji-only praise) for free; the AI sees only NEW,
 * unclear items, all in one small call. Verdicts are remembered, so nothing is judged twice.
 */
function pm_agent_junk_judge(string $brand): array
{
    pm_brand_set($brand);
    $all = pm_load('fb_judged', fn() => []);
    $known = (array)($all[$brand] ?? []);
    $new = [];
    $inbound = pm_fb_inbound($brand);
    // copy-paste spam: the same words posted more than once
    $norm = fn($t) => preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($t));
    $seen = [];
    foreach ($inbound as $it) {
        $k = $norm($it['text']);
        if (mb_strlen($k) >= 12) {
            $seen[$k] = ($seen[$k] ?? 0) + 1;
        }
    }
    foreach ($inbound as $id => $it) {
        if (isset($known[$id])) {
            $known[$id]['hidden'] = $it['hidden'];
            $known[$id]['answered'] = $it['answered'] || $known[$id]['answered'];
            continue;
        }
        $t = trim($it['text']);
        if ($it['kind'] === 'review') {
            if (trim($t) === '') {
                continue; // a star rating without words: nothing to judge
            }
            $new[$id] = $it; // reviews can only be answered, never removed
            continue;
        }
        if (pm_spam_signal($t)) {
            $it += ['verdict' => 'scam', 'action' => 'delete', 'reason' => 'Scam, phishing or spam pattern', 'reply' => ''];
        } elseif (($seen[$norm($t)] ?? 0) >= 2) {
            $it += ['verdict' => 'scam', 'action' => 'delete', 'reason' => 'Same text posted more than once (copy-paste spam)', 'reply' => ''];
        } elseif ($t === '' || mb_strlen(preg_replace('/[\p{So}\p{Sk}\p{P}\s]+/u', '', $t)) <= 2 || preg_match('/^(nice|great|good|wow|amazing|thanks?|thank you|congrats?|congratulations|well done|love (it|this)|beautiful|zikomo|👍|❤️)[\s!.\p{So}]*$/iu', $t)) {
            $it += ['verdict' => 'fan', 'action' => 'like', 'reason' => 'Friendly reaction', 'reply' => ''];
        } else {
            $new[$id] = $it;
            continue;
        }
        $known[$id] = $it + ['state' => '', 'by' => 'code'];
    }
    $new = array_slice($new, 0, 30, true);
    if ($new) {
        $system = pm_agents_company_brief('tiny') . "\nYou moderate this business's Facebook Page. For each visitor item decide the verdict: buyer (wants to buy, book or know a price), question, complaint, fan (friendly), "
            . "off_topic (unrelated chatter or someone advertising their own business), abusive (insults, hate, explicit), scam (fake offers, phishing, impersonation, hacked-account or money schemes). "
            . "Action: reply (buyer, question, complaint), like (fan), hide (off_topic, abusive: the writer still sees it, others don't), delete (scam only), ignore. "
            . "For reply write the reply: 1 to 3 short warm sentences in their language, answer only from the facts, invite buyers to WhatsApp or private message for prices, never promise times or results; complaints: apologise, take it to private message. "
            . "Watch for disguised junk: fake 'Meta support' or 'page will be disabled' warnings, prize or giveaway claims, people selling followers or other services, links to unknown sites, romance or dating bait, and copy-paste comments. A stranger's link that is not clearly about our business is off_topic at least. "
            . "For a review ('where': review) the only actions are reply or ignore. When unsure between two verdicts, prefer the milder action. Reply JSON only.";
        $user = json_encode(array_map(fn($i) => ['id' => $i['id'], 'where' => $i['kind'], 'from' => $i['from'], 'text' => mb_substr($i['text'], 0, 300), 'has_link' => (bool)preg_match('#https?://|www\.#i', $i['text']), 'on_our_post' => $i['post']], array_values($new)), JSON_UNESCAPED_UNICODE)
            . "\nJSON: {\"items\":[{\"id\":\"\",\"verdict\":\"\",\"action\":\"\",\"reason\":\"max 8 words\",\"reply\":\"\"}]}";
        $out = pm_agent_json(pm_claude($system, $user, false, 300 + 120 * count($new), 'write'));
        foreach ((array)($out['items'] ?? []) as $v) {
            $id = (string)($v['id'] ?? '');
            if (!isset($new[$id])) {
                continue;
            }
            $act = in_array($v['action'] ?? '', ['reply', 'like', 'hide', 'delete', 'ignore'], true) ? $v['action'] : 'ignore';
            $verdict = (string)($v['verdict'] ?? 'question');
            if ($act === 'delete' && $verdict !== 'scam') {
                $act = 'hide'; // only scams are deleted
            }
            if ($new[$id]['kind'] === 'review' && !in_array($act, ['reply', 'ignore'], true)) {
                $act = $verdict === 'fan' ? 'reply' : 'ignore'; // reviews can't be hidden or deleted
            }
            $known[$id] = $new[$id] + ['verdict' => $verdict, 'action' => $act, 'reason' => (string)($v['reason'] ?? ''), 'reply' => trim((string)($v['reply'] ?? '')), 'state' => '', 'by' => 'ai'];
        }
    }
    // already dealt with on Facebook itself
    foreach ($known as $id => &$k) {
        if ($k['state'] === '' && (($k['action'] === 'hide' && $k['hidden']) || ($k['action'] === 'reply' && $k['answered']))) {
            $k['state'] = 'done';
        }
    }
    unset($k);
    $known = array_filter($known, fn($k) => strtotime((string)$k['at']) > time() - 45 * 86400 || $k['state'] === '');
    $all[$brand] = $known;
    pm_save('fb_judged', $all);
    if ($new) {
        pm_agent_log('Social', count($new) . ' new visitor item(s) judged');
    }
    return $known;
}

/** Open suggestions, grouped for one-click handling. */
function pm_fb_suggestions(string $brand): array
{
    $open = array_filter((array)(pm_load('fb_judged', fn() => [])[$brand] ?? []), fn($k) => $k['state'] === '' && $k['action'] !== 'ignore' && !($k['action'] === 'like' && $k['liked']));
    $groups = [
        'delete' => ['label' => 'Delete scams and spam', 'why' => 'Fake offers and phishing make the Page look abandoned and put visitors at risk.', 'items' => []],
        'hide' => ['label' => 'Hide off-topic and abusive posts', 'why' => 'Hidden from everyone except the writer and their friends, so there is no fight.', 'items' => []],
        'reply' => ['label' => 'Answer questions, buyers and complaints', 'why' => 'Fast public answers win the sale and show every visitor that someone is there.', 'items' => []],
        'like' => ['label' => 'Like friendly comments', 'why' => 'Costs nothing, makes fans comment again.', 'items' => []],
        'ignore' => ['label' => '', 'why' => '', 'items' => []],
    ];
    foreach ($open as $k) {
        $groups[$k['action']]['items'][] = $k;
    }
    foreach ($groups as &$g) {
        usort($g['items'], fn($a, $b) => [($b['verdict'] ?? '') === 'buyer', $b['at']] <=> [($a['verdict'] ?? '') === 'buyer', $a['at']]);
    }
    unset($g);
    return array_filter($groups, fn($g) => $g['items']);
}

/** Does one judged item (or skips it). $text overrides the drafted reply. Returns [ok, message]. */
function pm_fb_do_item(string $brand, string $id, string $how = '', string $text = ''): array
{
    $all = pm_load('fb_judged', fn() => []);
    $k = $all[$brand][$id] ?? null;
    if (!$k) {
        return [false, 'That item is gone.'];
    }
    $how = $how ?: $k['action'];
    if ($how === 'skip') {
        $all[$brand][$id]['state'] = 'skipped';
        pm_save('fb_judged', $all);
        return [true, 'Skipped.'];
    }
    $text = trim($text ?: (string)$k['reply']);
    [$ok, $m] = match ($how) {
        'delete' => pm_fb_action($brand, 'delete_comment', $id),
        'hide' => pm_fb_action($brand, 'hide', $id),
        'like' => pm_fb_action($brand, 'like', $id),
        'reply' => $text === '' ? [false, 'No reply text.'] : pm_fb_action($brand, 'reply', $id, $text),
        default => [false, 'Unknown action.'],
    };
    if ($ok || str_contains((string)$m, 'does not exist') || str_contains((string)$m, 'Unsupported')) {
        $all[$brand][$id]['state'] = $ok ? 'done' : 'gone';
        pm_save('fb_judged', $all);
        if ($ok && $how === 'reply' && ($k['verdict'] ?? '') === 'buyer') {
            pm_fb_capture_buyers($brand);
        }
    }
    return [$ok, $m];
}

/** One click for a whole group. Returns [done, failed]. */
function pm_fb_do_group(string $brand, string $action, int $max = 30): array
{
    $done = 0;
    $fail = 0;
    foreach (array_slice(pm_fb_suggestions($brand)[$action]['items'] ?? [], 0, $max) as $k) {
        pm_fb_do_item($brand, $k['id'])[0] ? $done++ : $fail++;
    }
    pm_agent_log('Social', "One-click $action: $done done" . ($fail ? ", $fail failed" : ''));
    return [$done, $fail];
}

/**
 * One autopilot round for a brand (hourly). The judge runs only when there is something new (no AI otherwise).
 * safe: deletes clear scams caught by code, hides what the judge marks off-topic or abusive, likes friendly comments, adds buyers to leads,
 *       keeps replies as one-click suggestions, refreshes the daily playbook (reused when nothing changed).
 * bold: also posts the replies to questions and buyers (never to complaints: those stay yours).
 * It never deletes our own posts, never sends private messages and never spends money.
 */
function pm_social_autopilot(string $brand, bool $force = false): string
{
    $lvl = pm_autopilot_level($brand);
    $fb = pm_social_cfg($brand);
    if ($lvl === 'off' || !$fb['ready']) {
        return 'off';
    }
    $state = pm_load('autopilot', fn() => []);
    if (!$force && (time() - (int)($state[$brand]['at'] ?? 0)) < 3000) {
        return 'waiting';
    }
    $gg = pm_graph_guard();
    if (!$force && ((int)$gg['until'] > time() || (int)$gg['pct'] >= 75)) {
        return 'resting: Facebook limit at ' . (int)$gg['pct'] . '%'; // leave room for the people using the app
    }
    $state[$brand]['at'] = time();
    pm_save('autopilot', $state);
    pm_brand_set($brand);
    $did = ['deleted' => 0, 'hidden' => 0, 'liked' => 0, 'replied' => 0];
    try {
        $known = pm_agent_junk_judge($brand);
    } catch (Throwable) {
        $known = (array)(pm_load('fb_judged', fn() => [])[$brand] ?? []);
    }
    foreach ($known as $id => $k) {
        if ($k['state'] !== '') {
            continue;
        }
        $go = match ($k['action']) {
            'delete' => $k['by'] === 'code' || $lvl === 'bold',
            'hide', 'like' => true,
            'reply' => $lvl === 'bold' && in_array($k['verdict'], ['buyer', 'question'], true) && $k['reply'] !== '' && strtotime((string)$k['at']) > time() - 14 * 86400,
            default => false,
        };
        if ($go && pm_fb_do_item($brand, (string)$id)[0]) {
            $did[['delete' => 'deleted', 'hide' => 'hidden', 'like' => 'liked', 'reply' => 'replied'][$k['action']]]++;
        }
    }
    $buyers = pm_fb_capture_buyers($brand);
    $pb = pm_load('engage_playbook', fn() => [])[$brand] ?? [];
    $hour = (int)date('G');
    if (($pb['day'] ?? '') !== date('Y-m-d') && $hour >= 6 && $hour <= 18) {
        try {
            pm_agent_engage_playbook($brand);
        } catch (Throwable) {
        }
    }
    $msg = "Autopilot ($lvl): {$did['deleted']} scam(s) deleted, {$did['hidden']} hidden, {$did['liked']} liked" . ($lvl === 'bold' ? ", {$did['replied']} replied" : '') . ", $buyers buyer(s) added to leads";
    if (array_sum($did) + $buyers > 0) {
        pm_agent_log('Social', $msg);
    }
    return $msg;
}

/** Today's to-do for the Page, worked out in code: what's waiting, what's planned, what's missing. */
function pm_social_today(string $brand): array
{
    $sug = pm_fb_suggestions($brand);
    $ib = pm_fb_inbox($brand);
    $waiting = array_filter($ib['threads'], fn($t) => $t['waiting']);
    $posts = array_filter(pm_social_posts(), fn($p) => ($p['brand'] ?? 'promanaged') === ($brand === 'travel' ? 'travel' : 'promanaged'));
    $queued = array_filter($posts, fn($p) => in_array($p['status'] ?? '', ['approved', 'scheduled'], true) && strtotime((string)($p['when'] ?? '')) > time() - 3600);
    $drafts = array_filter($posts, fn($p) => ($p['status'] ?? '') === 'draft');
    $last = pm_fb_posts($brand, 1)['posts'][0]['at'] ?? '';
    $pb = pm_load('engage_playbook', fn() => [])[$brand] ?? null;
    $todo = [];
    if ($waiting) {
        $todo[] = ['n' => count($waiting), 'text' => count($waiting) . ' Messenger conversation(s) waiting for an answer' . (array_filter($waiting, fn($t) => !$t['window']) ? ' (some are past Facebook\'s 24-hour limit: answer from the Facebook app)' : ''), 'link' => '?tab=social&view=inbox', 'btn' => 'Answer'];
    }
    if ($drafts) {
        $todo[] = ['n' => count($drafts), 'text' => count($drafts) . ' planned post(s) waiting for your approval', 'link' => '?tab=social', 'btn' => 'Approve'];
    }
    if (!$queued && !$drafts) {
        $todo[] = ['n' => 1, 'text' => 'Nothing is scheduled to post' . ($last ? ' (last post ' . date('j M', strtotime($last)) . ')' : '') . ': plan this week\'s posts', 'link' => '?tab=social', 'btn' => 'Plan posts'];
    }
    if (!$pb || $pb['day'] !== date('Y-m-d')) {
        $todo[] = ['n' => 1, 'text' => 'Today\'s engagement playbook is not made yet', 'link' => '', 'btn' => ''];
    } elseif ($open = count(array_filter($pb['tasks'], fn($t) => !$t['done']))) {
        $todo[] = ['n' => $open, 'text' => "$open engagement task(s) left in today's playbook", 'link' => '#playbook', 'btn' => 'Go'];
    }
    return ['suggestions' => $sug, 'todo' => $todo, 'inbox_error' => $ib['error']];
}
