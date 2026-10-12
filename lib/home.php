<?php
/**
 * The agency overview: the whole marketing firm on one screen, each function a department with its own head count, numbers, state and next step. Everything here is read from what the app
 * already stores (leads, the Director's brief, the last Optimise check, the post plan, the scheduler heartbeat): nothing is fetched over the network and no AI is called, so the page is instant.
 *   pm_home_data($brand)     every number the overview shows
 *   pm_home_depts($brand,$d) the department cards: mission, numbers, state, next steps, staff
 *   pm_home_activity()       the latest lines of the agents' diary for the business
 */

/** One team member: [name, what they do, on duty?, why they are off]. */
function pm_home_staff(string $brand, array $d): array
{
    $en = (array)($d['sales']['enabled'] ?? []);
    $ai = !empty($d['ops']['ai']);
    $fb = !empty($d['social']['ready']);
    $proposals = !empty(pm_brand_profile($brand)['proposals']);
    $row = fn(string $n, string $what, bool $on, string $why = '') => ['name' => $n, 'what' => $what, 'on' => $on, 'why' => $on ? '' : $why];
    $needAi = $ai ? '' : 'needs an AI key';
    return [
        'sales' => [
            $row('Scout', 'Finds businesses that need what we sell', $ai && !empty($en['scout']), $ai ? 'switched off under Targeting and messages' : $needAi),
            $row('Contact Finder', 'Finds the owner, email and WhatsApp number', $ai && !empty($en['contact']), $ai ? 'switched off under Targeting and messages' : $needAi),
            $row('Qualifier', 'Scores who deserves a message', $ai && !empty($en['qualifier']), $ai ? 'switched off under Targeting and messages' : $needAi),
            $row('Researcher', 'Reads their website and pages', $ai && !empty($en['research']), $ai ? 'switched off under Targeting and messages' : $needAi),
            $row('Writer', 'Drafts the first email and WhatsApp', $ai && !empty($en['writer']), $ai ? 'switched off under Targeting and messages' : $needAi),
            $row('Follow-up', 'Drafts the nudge when they go quiet', $ai && !empty($en['followup']), $ai ? 'switched off under Targeting and messages' : $needAi),
            $row('Proposal writer', 'Tailors the quotation', $ai && $proposals && !empty($en['proposals']), $proposals ? ($ai ? 'switched off under Targeting and messages' : $needAi) : 'proposals are for ProManaged IT and Travel Malawi'),
        ],
        'social' => [
            $row('Planner', 'Writes the week\'s posts for your approval', $fb || (int)($d['social']['planned'] ?? 0) > 0, 'connect the Facebook Page'),
            $row('Community manager', 'Answers and tidies comments (autopilot)', $fb && ($d['social']['autopilot'] ?? 'safe') !== 'off', $fb ? 'autopilot is off' : 'connect the Facebook Page'),
            $row('Playbook', 'Who to engage today, with the words', $fb, 'connect the Facebook Page'),
            $row('Idea room', 'Four specialists suggest post ideas; an editor scores them', $ai, $needAi),
            $row('Editor', 'Reads the AI\'s posts and sends weak ones back once', $ai && function_exists('pm_editor_on') && pm_editor_on($brand), $ai ? 'switched off in the Plan\'s Publishing rules' : $needAi),
        ],
        'brand' => [
            $row('Creative director', 'Proposes whole kits and sends weak ones back for another round', $ai && function_exists('pm_studio_can_draw') && pm_studio_can_draw(), $ai ? 'this server cannot draw pictures' : $needAi),
            $row('Copywriter', 'Writes the profile words, three options each', $ai, $needAi),
            $row('Writers\' room', 'Four writers, four angles, for the cover wording', $ai, $needAi),
            $row('Copy editor', 'Holds every kit, line and post to the human-voice rules', true),
            $row('Art critic', 'Scores each kit: readable, short, human, fresh', true),
            $row('Photo critic', 'Reads every photo for a cover and says how to fix it', function_exists('pm_studio_can_draw') && pm_studio_can_draw(), 'this server cannot read pictures'),
            $row('Photo director', 'Writes shot briefs for the photos to take next', $ai, $needAi),
            $row('Producer', 'Draws the best three for you to choose from', function_exists('pm_studio_can_draw') && pm_studio_can_draw(), 'this server cannot draw pictures'),
        ],
        'insights' => [
            $row('Director', 'Reads the numbers and sets today\'s strategy', $ai, $needAi),
            $row('Auditor', 'Judges the Page and what to change', $ai && $fb, $ai ? 'connect the Facebook Page' : $needAi),
        ],
        'ops' => [
            $row('Scheduler', 'Sends, posts and refreshes on time', ($d['ops']['scheduler_age'] ?? null) !== null && (int)$d['ops']['scheduler_age'] < 7200, 'it has not run recently: install the scheduled task (Operations > Health and backups)'),
            $row('Mail room', 'Sends your approved emails', !empty($d['ops']['mail']), 'add the mail login under Operations > Email'),
            $row('Inbox reader', 'Reads replies and classifies them', !empty($d['ops']['imap']), 'add the mailbox login under Operations > Email'),
        ],
    ];
}

/** The latest lines of the agents' diary for this business (the diary prefixes other businesses with their name). */
function pm_home_activity(string $brand, int $n = 8): array
{
    $log = (array)pm_load('agent_log', fn() => []);
    $name = pm_brand_name($brand);
    $out = [];
    foreach ($log as $r) {
        $m = (string)($r['msg'] ?? '');
        $mine = $brand === 'promanaged' ? !preg_match('/^\[[^\]]+\] /', $m) : str_starts_with($m, '[' . $name . '] ');
        if (!$mine) {
            continue;
        }
        $out[] = ['at' => (string)($r['at'] ?? ''), 'agent' => (string)($r['agent'] ?? ''), 'msg' => preg_replace('/^\[[^\]]+\] /', '', $m), 'error' => (bool)preg_match('/\b(fail(ed)?|could not|error|stalled)\b/i', $m)];
        if (count($out) >= $n) {
            break;
        }
    }
    return $out;
}

/** Every number the overview shows, for one business. Reads stored data only. */
function pm_home_data(string $brand): array
{
    $brand = pm_brand_norm($brand);
    $was = pm_brand();
    pm_brand_set($brand);
    try {
        $ps = pm_pipeline_stats($brand);
        $st = (array)$ps['status'];
        $cfg = pm_agents_config($brand);
        $run = pm_run_state();
        $brief = (array)(pm_load('director', fn() => [])[$brand] ?? []);
        // the social desk
        $fb = pm_social_cfg($brand);
        $posts = array_filter(pm_social_posts(), fn($p) => ($p['brand'] ?? 'promanaged') === $brand);
        $now = time();
        $planned = count(array_filter($posts, fn($p) => in_array($p['status'] ?? '', ['approved', 'scheduled'], true) && strtotime((string)($p['when'] ?? '')) >= $now - 3600));
        $waitingPosts = count(array_filter($posts, fn($p) => in_array($p['status'] ?? '', ['draft', 'review'], true)));
        $pub28 = count(array_filter($posts, fn($p) => ($p['status'] ?? '') === 'published' && strtotime((string)($p['when'] ?? '')) > $now - 28 * 86400));
        $pb = (array)(pm_load('engage_playbook', fn() => [])[$brand] ?? []);
        $pbOpen = count(array_filter((array)($pb['tasks'] ?? []), fn($t) => empty($t['done'])));
        $page = (array)(pm_load('social_page', fn() => [])[$brand] ?? []);
        // the brand desk
        $opt = (array)(pm_opt_store($brand)['last'] ?? []);
        $design = function_exists('pm_studio_saved') ? pm_studio_saved($brand) : null;
        // operations
        $health = function_exists('pm_setup_health') ? pm_setup_health() : [];
        $okOf = function (string $key) use ($health): bool {
            foreach ($health as $r) {
                if ($r['key'] === $key) {
                    return (bool)$r['ok'];
                }
            }
            return false;
        };
        [$hOk, $hNeed] = function_exists('pm_setup_health_summary') ? pm_setup_health_summary($health) : [0, 0, 0, 0];
        // today, as it happens
        $mine = array_filter(pm_leads_read(), fn($l) => ($l['brand'] ?? 'promanaged') === $brand);
        $queue = (int)$ps['approved_waiting'];
        $next = $queue && function_exists('pm_ss_next_open') ? pm_ss_next_open($brand, time()) : 0;
        $today = date('Y-m-d');
        $postsToday = count(array_filter($posts, fn($p) => in_array($p['status'] ?? '', ['approved', 'scheduled'], true) && substr((string)($p['when'] ?? ''), 0, 10) === $today));
        $waitingPeople = count(array_filter((array)(pm_load('fb_judged', fn() => [])[$brand] ?? []), fn($r) => in_array($r['verdict'] ?? '', ['buyer', 'question', 'complaint'], true)
            && ($r['state'] ?? '') === '' && empty($r['answered']) && empty($r['private_reply_at']) && strtotime((string)($r['at'] ?? '')) > $now - 14 * 86400));
        $cacheHits = (int)(pm_load('ai_cache_stats', fn() => [])[$today]['hits'] ?? 0);
        return [
            'brand' => $brand,
            'sales' => ['leads' => (int)$ps['leads'], 'new' => (int)($st['new'] ?? 0), 'contacted' => (int)$ps['contacted'], 'replies' => (int)$ps['awaiting_reply'], 'drafts' => (int)$ps['drafts_unsent'],
                'followups_due' => (int)$ps['followups_due'], 'followups_to_draft' => (int)$ps['followups_to_draft'], 'approved' => (int)$ps['approved_waiting'], 'sent_today' => (int)$ps['sent_today'],
                'send_cap' => (int)($cfg['send_cap'] ?? 0), 'proposals' => (int)$ps['proposals_sent'], 'won' => (int)$ps['won'], 'enabled' => (array)($cfg['enabled'] ?? []),
                'run_state' => (string)($run['state'] ?? 'idle'), 'run_at' => (string)($run['finished'] ?? '')],
            'brief' => ['headline' => (string)($brief['headline'] ?? ''), 'priorities' => array_values(array_filter(array_map('strval', (array)($brief['priorities'] ?? [])))), 'at' => (int)($brief['at'] ?? 0), 'date' => (string)($brief['date'] ?? ''),
                'experiment' => (string)($brief['experiment'] ?? '')],
            'social' => ['ready' => $fb['ready'], 'ig' => $fb['ig_id'] !== '', 'autopilot' => $fb['ready'] ? pm_autopilot_level($brand) : 'off', 'planned' => $planned, 'waiting' => $waitingPosts, 'published28' => $pub28,
                'playbook_day' => (string)($pb['day'] ?? ''), 'playbook_open' => $pbOpen, 'playbook_total' => count((array)($pb['tasks'] ?? [])), 'followers' => (int)($page['followers'] ?? $page['fans'] ?? 0)],
            'brand' => ['score' => isset($opt['score']) ? (int)$opt['score'] : null, 'fixable' => (int)($opt['fixable'] ?? 0), 'checked' => (string)($opt['at'] ?? ''), 'design' => $design ? (string)(PM_SD_STYLES[$design['style']] ?? 'custom') : '',
                'copy' => count((array)(pm_opt_store($brand)['copy'] ?? [])), 'todo' => (array)($opt['todo'] ?? []), 'kits' => function_exists('pm_bt_pending') ? count(pm_bt_pending($brand)) : 0],
            'insights' => ['enquiries28' => function_exists('pm_sx_social_leads_count') ? pm_sx_social_leads_count($brand, 28) : 0, 'published28' => $pub28, 'won' => (int)$ps['won'], 'proposals' => (int)$ps['proposals_sent'], 'leads' => (int)$ps['leads'],
                'goal_posts' => function_exists('pm_social_goals') ? (int)pm_social_goals($brand)['posts_per_week'] : 0],
            'ops' => ['ai' => pm_agents_ready(), 'scheduler_age' => function_exists('pm_social_heartbeat_age') ? pm_social_heartbeat_age() : null, 'mail' => $okOf('smtp_' . $brand), 'imap' => $okOf('imap_' . $brand),
                'essentials_ok' => $hOk, 'essentials' => $hNeed, 'businesses' => count(pm_brand_ids())],
            'activity' => pm_home_activity($brand),
            'today' => ['sent' => (int)$ps['sent_today'], 'cap' => function_exists('pm_effective_send_cap') ? pm_effective_send_cap($cfg) : (int)($cfg['send_cap'] ?? 0), 'queue' => $queue,
                'next' => $next ? pm_ss_fmt($next) : ($queue ? 'paused' : ''), 'wa' => pm_wa_sent_today($mine), 'wa_cap' => (int)($cfg['wa_cap'] ?? 0), 'posts' => $postsToday,
                'pb_done' => ($pb['day'] ?? '') === $today ? count(array_filter((array)$pb['tasks'], fn($t) => !empty($t['done']))) : 0, 'pb_total' => ($pb['day'] ?? '') === $today ? count((array)$pb['tasks']) : 0,
                'people' => $waitingPeople, 'ai' => pm_usage_brand_today($brand), 'ai_budget' => pm_budget(), 'memory' => $cacheHits],
        ];
    } finally {
        pm_brand_set($was);
    }
}

/**
 * Everything that waits on the owner, by department: dept => list of [what, link, urgent]. An empty list means the department needs nothing from you.
 * Read from stored data only, like the rest of the overview.
 */
function pm_home_needs(string $brand, array $d): array
{
    $s = $d['sales'];
    $so = $d['social'];
    $br = $d['brand'];
    $op = $d['ops'];
    $t = $d['today'];
    $n = fn(int $x, string $one, string $many) => $x . ' ' . ($x === 1 ? $one : $many);
    $need = ['sales' => [], 'social' => [], 'brand' => [], 'insights' => [], 'ops' => []];
    $s['replies'] && $need['sales'][] = [$n($s['replies'], 'reply waits', 'replies wait') . ' for an answer', '?tab=agents&status=awaiting', true];
    $s['drafts'] && $need['sales'][] = [$n($s['drafts'], 'first message is', 'first messages are') . ' ready to read and approve', '?tab=agents&status=drafted', false];
    $s['followups_to_draft'] && $need['sales'][] = [$n($s['followups_to_draft'], 'follow-up', 'follow-ups') . ' to draft', '?tab=agents#strategy', false];
    if (!$so['ready']) {
        $need['social'][] = ['Connect the Facebook Page', '?tab=settings&section=connections', true];
    } else {
        $t['people'] && $need['social'][] = [$n($t['people'], 'person waits', 'people wait') . ' for an answer on Facebook or Instagram', '?tab=social&view=inbox', true];
        $so['waiting'] && $need['social'][] = [$n($so['waiting'], 'post waits', 'posts wait') . ' for your approval', '?tab=social', false];
        $t['pb_total'] > $t['pb_done'] && $need['social'][] = [($t['pb_total'] - $t['pb_done']) . ' of ' . $n($t['pb_total'], 'playbook task', 'playbook tasks') . ' still to do today', '?tab=social&view=growth', false];
        !$so['planned'] && !$so['waiting'] && $need['social'][] = ['Nothing is scheduled to post: plan the week', '?tab=social', false];
    }
    $br['kits'] && $need['brand'][] = [$n($br['kits'], 'new kit waits', 'new kits wait') . ' for your choice', '?tab=social&view=kit', false];
    $br['score'] === null && $need['brand'][] = ['Check how the profiles stand', '?tab=social&view=optimise', false];
    $br['score'] !== null && $br['fixable'] && $br['score'] < 80 && $need['brand'][] = [$n($br['fixable'], 'profile fix is', 'profile fixes are') . ' ready (Page health ' . $br['score'] . '%)', '?tab=social&view=optimise', false];
    !$op['ai'] && $need['ops'][] = ['Add an AI key: the agents cannot write without one', '?tab=settings&section=connections', true];
    $op['scheduler_age'] === null && $need['ops'][] = ['Set up the scheduler, so posts, emails and the morning playbook go out on their own', '?tab=settings&section=health', false];
    $op['scheduler_age'] !== null && $op['scheduler_age'] >= 7200 && $need['ops'][] = ['The scheduler last ran ' . max(1, intdiv((int)$op['scheduler_age'], 3600)) . ' h ago', '?tab=settings&section=health', true];
    !$op['mail'] && $need['ops'][] = ['Set up the mailbox so emails can be sent', '?tab=settings&section=email', true];
    $op['essentials_ok'] < $op['essentials'] && $op['mail'] && $op['ai'] && $need['ops'][] = [$op['essentials_ok'] . ' of ' . $op['essentials'] . ' essentials ready', '?tab=settings&section=health', false];
    return $need;
}

/** The department cards. state: ok | warn | todo; stats are label => value; next steps are [label, href, primary]. */
function pm_home_depts(string $brand, array $d): array
{
    $s = $d['sales'];
    $so = $d['social'];
    $br = $d['brand'];
    $in = $d['insights'];
    $op = $d['ops'];
    $staff = pm_home_staff($brand, $d);
    $n = fn(int $x, string $one, string $many) => $x . ' ' . ($x === 1 ? $one : $many);
    // Sales
    [$sSt, $sTxt] = !$op['ai'] ? ['todo', 'Needs an AI key before the team can work'] : ($s['replies'] > 0 ? ['warn', $n($s['replies'], 'reply waits', 'replies wait') . ' for an answer'] : ($s['drafts'] > 0 ? ['warn', $n($s['drafts'], 'draft waits', 'drafts wait') . ' for you to read'] : ['ok', 'Nothing is waiting on you']));
    // Social
    [$soSt, $soTxt] = !$so['ready'] ? ['todo', 'Connect the Facebook Page to start'] : ($so['waiting'] > 0 ? ['warn', $n($so['waiting'], 'post waits', 'posts wait') . ' for your approval'] : ($so['planned'] === 0 ? ['warn', 'Nothing is scheduled to post'] : ['ok', $n($so['planned'], 'post', 'posts') . ' lined up']));
    // Brand
    [$bSt, $bTxt] = $br['kits'] > 0 ? ['warn', $n($br['kits'], 'new kit from the brand team waits', 'new kits from the brand team wait') . ' for your choice'] : ($br['score'] === null ? ['todo', 'No check yet: see how the profiles stand'] : ($br['score'] < 60 ? ['warn', 'Page health ' . $br['score'] . '%: ' . $n($br['fixable'], 'fix is', 'fixes are') . ' ready'] : ['ok', 'Page health ' . $br['score'] . '%']));
    // Insights
    [$iSt, $iTxt] = $in['leads'] === 0 ? ['todo', 'Numbers appear once the first leads are in'] : ['ok', $n($in['enquiries28'], 'enquiry', 'enquiries') . ' from social in 4 weeks'];
    // Operations
    [$oSt, $oTxt] = $op['scheduler_age'] === null ? ['warn', 'The scheduler has never run'] : ($op['scheduler_age'] >= 7200 ? ['warn', 'The scheduler last ran ' . max(1, intdiv((int)$op['scheduler_age'], 3600)) . ' h ago'] : ($op['essentials_ok'] < $op['essentials'] ? ['warn', $op['essentials_ok'] . ' of ' . $op['essentials'] . ' essentials ready'] : ['ok', 'Everything essential is connected']));
    $on = fn(array $rows) => count(array_filter($rows, fn($r) => $r['on']));
    $cards = [
        'sales' => ['mission' => 'Finds businesses, writes to them, follows up and prepares the quotation.', 'state' => $sSt, 'text' => $sTxt,
            'stats' => ['Leads' => $s['leads'], 'Replies waiting' => $s['replies'], 'Drafts to read' => $s['drafts'], 'Follow-ups due' => $s['followups_due'], 'Sent today' => $s['sent_today'] . ($s['send_cap'] ? ' of ' . $s['send_cap'] : '')],
            'next' => array_values(array_filter([[$s['replies'] ? 'Answer the replies' : 'Open the leads', '?tab=agents', true], $s['followups_to_draft'] > 0 ? ['Draft ' . $s['followups_to_draft'] . ' follow-up' . ($s['followups_to_draft'] === 1 ? '' : 's'), '?tab=agents#strategy', false] : null, ['WhatsApp outreach', '?tab=whatsapp', false]]))],
        'social' => ['mission' => 'Plans and publishes the posts, answers the community, and tells you who to engage today.', 'state' => $soSt, 'text' => $soTxt,
            'stats' => ['Posts lined up' => $so['planned'], 'Waiting approval' => $so['waiting'], 'Published (4 weeks)' => $so['published28'], 'Playbook tasks open' => $so['playbook_open'], 'Followers' => $so['followers'] ?: '-'],
            'next' => $so['ready'] ? array_values(array_filter([[$so['waiting'] ? 'Approve the posts' : 'Plan the posts', '?tab=social', true], ['Today\'s playbook', '?tab=social&view=growth', false], ['Inbox', '?tab=social&view=inbox', false]])) : [['Connect Facebook', '?tab=settings&section=connections', true]]],
        'brand' => ['mission' => 'Designs the covers and pictures, writes the profile words and keeps every channel in good order.', 'state' => $bSt, 'text' => $bTxt,
            'stats' => ['Page health' => $br['score'] === null ? '-' : $br['score'] . '%', 'Fixes ready' => $br['fixable'], 'Design in use' => $br['design'] !== '' ? $br['design'] : 'Classic', 'Profile words written' => $br['copy']],
            'next' => [[$br['score'] === null || $br['fixable'] ? 'Fix what is missing' : 'See the channels', '?tab=social&view=optimise', true], ['Open the Studio', '?tab=social&view=studio', false], ['Brand voice', '?tab=social&view=voice', false]]],
        'insights' => ['mission' => 'Shows what worked, what it cost, and what the strategy should be next.', 'state' => $iSt, 'text' => $iTxt,
            'stats' => ['Leads' => $in['leads'], 'Proposals out' => $in['proposals'], 'Won' => $in['won'], 'Social enquiries (4 weeks)' => $in['enquiries28'], 'Weekly post goal' => $in['goal_posts'] ?: '-'],
            'next' => [['Open the results', '?tab=social&view=results', true], ['Run the audit', '?tab=social&view=audit', false], ['Strategy brief', '?tab=home#brief', false]]],
        'ops' => ['mission' => 'Keeps the machinery running: the scheduler, the mail room, the connections and the team.', 'state' => $oSt, 'text' => $oTxt,
            'stats' => ['AI' => $op['ai'] ? 'On' : 'No key', 'Mail' => $op['mail'] ? 'Ready' : 'Not set', 'Replies read' => $op['imap'] ? 'Yes' : 'No', 'Essentials' => $op['essentials_ok'] . '/' . $op['essentials'], 'Businesses' => $op['businesses']],
            'next' => [['Setup health', '?tab=settings&section=health', true], ['Business details', '?tab=settings&section=business', false], ['Add a business', '?tab=business&new=1', false]]],
    ];
    foreach ($cards as $k => &$c) {
        $c['staff'] = $staff[$k];
        $c['on_duty'] = $on($staff[$k]);
    }
    unset($c);
    return $cards;
}

/** A business in a line: for the strip of all businesses. Cheap: counts only, no pipeline work. */
function pm_home_glance(string $brand): array
{
    $leads = array_filter(pm_leads_read(), fn($l) => ($l['brand'] ?? 'promanaged') === $brand);
    $replies = count(array_filter($leads, fn($l) => ($l['status'] ?? '') !== 'optout' && (($l['status'] ?? '') === 'replied' || !empty($l['awaiting_reply_since']))));
    $posts = count(array_filter(pm_social_posts(), fn($p) => ($p['brand'] ?? 'promanaged') === $brand && in_array($p['status'] ?? '', ['draft', 'review'], true)));
    return ['leads' => count($leads), 'replies' => $replies, 'posts_waiting' => $posts, 'fb' => pm_social_cfg($brand)['ready']];
}
