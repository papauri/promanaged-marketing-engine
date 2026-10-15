<?php
/**
 * The department heads' goals: the firm's goal for the month (signed clients and replies) broken down into what each head must do, and checked by code against
 * what we have (no AI). A head that is behind works out who holds it up and asks that head on the team board; the head asked answers in the agents' conversation
 * with what it will do, and the ask comes down by itself once the goal is met again. The Director hears every head's standing before the morning meeting and orders
 * the ones that are behind first; each agent's instructions carry its own goal; the stand-up and the Overview show each head against its goal.
 * The chain: replies still needed ÷ our reply rate = messages to send; messages need written drafts, drafts need studied leads with an email, those need scouted businesses.
 * Data: data/heads.json {brand: {at, line, goals: {dept: {goal, have, ok, why, ask_to, ask}}}}.
 */

/** Every head's goal and where it stands, from what is stored now. No AI, nothing written. */
function pm_heads_goals(string $brand): array
{
    require_once __DIR__ . '/director.php'; // the pipeline numbers
    function_exists('pm_social_cfg') || require_once __DIR__ . '/social_modules.php'; // the daily run does not load the social screens: Social's goal needs them
    $was = pm_brand();
    pm_brand_set($brand);
    try {
        $L = array_filter(pm_leads_read(), fn($l) => ($l['brand'] ?? 'promanaged') === $brand);
        $cfg = pm_agents_config($brand);
        $fg = pm_firm_goal($brand);
        $st = pm_pipeline_stats($brand);
        $n = fn(int $x, string $one, string $many) => $x . ' ' . ($x === 1 ? $one : $many);
        $mail = fn($l) => (bool)filter_var($l['email'] ?? '', FILTER_VALIDATE_EMAIL) && !(!empty($l['email_bad']) && strcasecmp((string)$l['email_bad'], (string)$l['email']) === 0);
        $open = fn($l) => empty($l['listen']) && in_array($l['status'] ?? '', ['new', 'qualified', 'drafted'], true) && empty($l['sent']) && empty($l['wa_sent']);
        $week = strtotime('-7 days');

        // the chain from the month's goal down to a working day
        $workdays = 0;
        for ($d = strtotime('today'); date('Y-m', $d) === date('Y-m'); $d = strtotime('+1 day', $d)) {
            $workdays += (int)date('N', $d) <= 5 ? 1 : 0;
        }
        $workdays = max(1, $workdays);
        $known = (int)$st['contacted'] >= 20; // too few messages to trust our own reply rate: assume one in ten
        $rate = $known ? max(0.03, $st['replied'] / max(1, $st['contacted'])) : 0.10;
        $left = max(0, $fg['replies'][1] - $fg['replies'][0]);
        $perDay = $left ? max(1, (int)ceil($left / $rate / $workdays)) : max(1, (int)ceil((int)$cfg['send_cap'] / 3)); // goal met: keep a steady rhythm
        $cap = max(1, (int)$cfg['send_cap']);
        $perDay = min($perDay, $cap);
        $line = $fg['line'] !== '' ? "To win the {$left} replies still needed at " . ($known ? 'our' : 'an assumed') . ' reply rate of ' . round($rate * 100) . "%, we send {$perDay} messages a working day for {$workdays} working days." : '';

        $sent7 = 0;
        foreach ($L as $l) {
            foreach (array_merge((array)($l['sent'] ?? []), (array)($l['wa_sent'] ?? [])) as $at) {
                $sent7 += strtotime((string)$at) > $week ? 1 : 0;
            }
        }
        $ready = (int)$st['drafts_unsent'] + (int)$st['approved_waiting'];
        $raw = count(array_filter($L, fn($l) => $open($l) && $mail($l) && empty($l['drafts']['email_body']))); // leads with an email that wait for a draft
        $noMail = count(array_filter($L, fn($l) => $open($l) && !$mail($l)));
        $openN = count(array_filter($L, $open));
        $unstudied = count(array_filter($L, fn($l) => $open($l) && $mail($l) && empty($l['research']['at'])));
        $added7 = count(array_filter($L, fn($l) => empty($l['listen']) && strtotime((string)($l['created'] ?? '')) > $week));
        $waitOld = array_filter($L, fn($l) => ($l['status'] ?? '') !== 'optout' && !empty($l['awaiting_reply_since']) && strtotime((string)$l['awaiting_reply_since']) < time() - 86400);
        $waitDrafted = count(array_filter($waitOld, fn($l) => !empty($l['reply_draft']['body'])));
        $bs = pm_backlog_state($L, $cfg, $brand);

        $g = [];
        $set = function (string $dept, string $goal, string $have, bool $ok, string $why = '', string $to = '', string $ask = '') use (&$g) {
            $g[$dept] = ['goal' => $goal, 'have' => $have, 'ok' => $ok, 'why' => $why, 'ask_to' => $ok ? '' : $to, 'ask' => $ok ? '' : $ask];
        };

        // the Director: the firm's goal itself
        $behind = $fg['pace'] === 'behind';
        $set('director', $fg['line'] !== '' ? "{$fg['signed'][1]} signed clients and {$fg['replies'][1]} replies this month" : 'Set a goal for the month', "{$fg['signed'][0]} signed, {$fg['replies'][0]} replies ({$fg['pace']})",
            !$behind, $behind ? 'We are behind the month\'s goal.' : '', 'all', $behind ? "We are behind: {$left} replies still needed in {$workdays} working days. Every head: put the leads closest to buying first and tell me what holds you up." : '');

        // the Sender: a week of messages out
        $needWeek = $perDay * min(5, $workdays);
        $sOk = $sent7 >= $needWeek || (int)$st['approved_waiting'] > 0;
        $sTo = (int)$st['drafts_unsent'] >= $perDay ? 'owner' : 'writers';
        $set('sender', "Send {$perDay} messages a working day", $n($sent7, 'message', 'messages') . ' sent in 7 days of ' . $needWeek, $sOk, $sOk ? '' : ($sTo === 'owner' ? $n((int)$st['drafts_unsent'], 'draft waits', 'drafts wait') . ' for approval.' : 'Too few drafts to send.'),
            $sTo, $sTo === 'owner' ? 'Please read and approve ' . $n(min((int)$st['drafts_unsent'], $perDay * 2), 'draft', 'drafts') . " today: we need {$perDay} out a working day to make the month's goal." : "I have only {$ready} ready and need {$perDay} a working day: please draft more.");

        // the Writers: a day's messages ready
        $wOk = $ready >= $perDay;
        $wTo = $raw > 0 ? '' : ($noMail > 0 ? 'contact' : 'scouts');
        $set('writers', "Keep {$perDay} messages ready to send", $n($ready, 'message', 'messages') . ' ready' . ((int)$st['drafts_no_email'] ? ' (' . (int)$st['drafts_no_email'] . ' of them with no email: WhatsApp only)' : ''), $wOk, $wOk ? '' : ($raw ? $n($raw, "lead with an email waits", "leads with an email wait") . " for a draft." : ($noMail ? $n($noMail, 'lead has', 'leads have') . ' no email.' : 'No leads left to write to.')),
            $wTo, $wTo === 'contact' ? 'I have no lead with an email left to write to: please find the emails of the best ' . min($noMail, $perDay * 2) . ' of the ' . $noMail . ' without one.' : "I have no lead left to write to: please bring {$needWeek} businesses this week that publish an email.");

        // the Contact Finder: the leads worth writing to can be reached
        $share = $openN ? 1 - $noMail / $openN : 1;
        $cOk = $share >= 0.6 || $noMail < $perDay;
        $set('contact', 'Most leads worth writing to have an email (6 in 10)', round($share * 100) . '% of ' . $openN . ' open leads' . ($cOk && $share < 0.6 && $noMail ? ' (' . $n($noMail, 'lead', 'leads') . ' without one; few enough to look up by hand)' : ''), $cOk, $cOk ? ($share < 0.6 && $noMail ? 'I keep looking for the ' . $n($noMail, 'missing email', 'missing emails') . '.' : '') : $n($noMail, 'lead has', 'leads have') . ' no email.',
            'research', 'Many of our leads have no email: when you study a business, bring back its published email and WhatsApp number first.');

        // the Researchers: the leads we write to are studied first
        $rOk = $unstudied <= $perDay;
        $set('research', 'Study the leads before the Writers write to them', $n($unstudied, 'lead with an email is', 'leads with an email are') . ' not studied yet', $rOk, $rOk ? '' : 'I study the ones the Writers need first.',
            $unstudied > 3 * (int)($cfg['research_per_day'] ?? 30) ? 'owner' : '', 'More leads wait to be studied than my daily limit allows: raise the research limit or let me catch up for a few days.');

        // the Scouts: enough new businesses to keep the chain fed
        $want = min($needWeek, (int)$cfg['new_per_day'] * 7);
        $scOk = $added7 >= $want || $bs['pause'] || $openN >= $needWeek * 2; // holding while drafts pile up, or a big stock left, is the plan
        $set('scouts', "Bring {$want} new businesses a week", $n($added7, 'business', 'businesses') . ' added in 7 days' . ($bs['pause'] ? ' (holding: ' . $bs['backlog'] . ' drafts wait to be sent)' : ''), $scOk,
            $scOk ? '' : 'Short of new businesses.', (int)$cfg['new_per_day'] * 5 < $needWeek ? 'owner' : 'director',
            (int)$cfg['new_per_day'] * 5 < $needWeek ? "My limit of {$cfg['new_per_day']} new leads a day is too low for the goal: raise it in the settings." : 'I am short of new businesses: point my searches at the kinds of business and towns that answer.');

        // Follow-up: everyone who has not answered has the next follow-up written ahead, and the due ones go out on time
        $fu = pm_followup_board($L, $cfg);
        $fuMissing = $fu['waiting'] - $fu['prepared'];
        $set('followup', 'Have the next follow-up ready for everyone who has not answered, and send each one on time',
            $n($fu['waiting'], 'lead has', 'leads have') . ' not answered, ' . $fu['prepared'] . ' prepared, ' . $fu['due'] . ' due now' . (!$fu['due'] && $fu['next'] !== '' ? ' (next ' . pm_followup_day($fu['next']) . ')' : ''),
            $fuMissing === 0 && $fu['due'] === 0,
            $fu['due'] ? ($fu['due_ready'] ? $n($fu['due_ready'], 'follow-up is', 'follow-ups are') . ' due and ready to send.' : 'I write the due ones next.') : ($fuMissing ? "I prepare the other {$fuMissing} next." : ''),
            $fu['due_ready'] ? 'owner' : '', $n($fu['due_ready'], 'follow-up is', 'follow-ups are') . ' due and ready: please tick them on Leads and press Follow up.');

        // Replies: nobody waits more than a day
        $wo = count($waitOld);
        $set('replies', 'Answer everyone who wrote back within a day', $n($wo, 'person has', 'people have') . ' waited over a day', $wo === 0, $wo ? ($waitDrafted ? $n($waitDrafted, 'answer is', 'answers are') . ' drafted.' : 'I draft the answers next.') : '',
            $waitDrafted ? 'owner' : '', 'People who wrote back have waited over a day: ' . $n($waitDrafted, 'answer is', 'answers are') . ' drafted for you to send.');

        // Social: enquiries from social media, and nobody left waiting on the Page
        $sg = function_exists('pm_social_goals') ? pm_social_goals($brand) : ['enquiries_per_week' => 2];
        $enq = function_exists('pm_sx_social_leads_count') ? pm_sx_social_leads_count($brand, 7) : 0;
        $enq += count(array_filter($L, fn($l) => !empty($l['listen']) && strtotime((string)($l['created'] ?? '')) > $week));
        $fb = function_exists('pm_social_cfg') && pm_social_cfg($brand)['ready'];
        $soOk = !$sg['enquiries_per_week'] || $enq >= $sg['enquiries_per_week'];
        $slc = function_exists('pm_sl_counts') ? pm_sl_counts($brand, $L) : ['answer' => 0, 'followup' => 0];
        $slDue = $slc['answer'] + $slc['followup']; // the people who came on social media and wait for us come before more enquiries
        $set('social', ($sg['enquiries_per_week'] ? "Bring {$sg['enquiries_per_week']} enquiries a week from social media" : 'Keep the Page answered') . ', and answer and follow up every social lead',
            $n($enq, 'enquiry', 'enquiries') . ' in 7 days, ' . $slDue . ' social ' . ($slDue === 1 ? 'lead waits' : 'leads wait') . ' for us', $soOk && !$slDue,
            $slDue ? $slc['answer'] . ' to answer and ' . $slc['followup'] . ' to follow up, drafted on Social › Social leads.' : ($soOk ? '' : ($fb ? 'I post more lead posts and listen wider.' : 'Our Facebook Page is not connected.')),
            $slDue || !$fb ? 'owner' : 'writers',
            $slDue ? 'People who came to us on social media wait: ' . $slc['answer'] . ' to answer and ' . $slc['followup'] . ' to follow up. The drafts are on Social › Social leads; please send them.'
                : ($fb ? 'Social is short of enquiries: give me the angles that won replies this month so the lead posts use them.' : 'Connect the Facebook Page so I can bring enquiries from it.'));

        return ['at' => date('Y-m-d H:i'), 'line' => $line, 'per_day' => $perDay, 'goals' => $g];
    } finally {
        pm_brand_set($was);
    }
}

/** What the head asked answers, in the first person, when another head asks it for help. */
function pm_heads_answer(string $to, string $from): string
{
    $by = pm_agent_first($from);
    return match ($to) {
        'writers' => "On it, $by: I put the leads with an email first, the warmest first.",
        'contact' => "On it, $by: I look for the missing emails of the best leads first.",
        'research' => "On it, $by: I bring back each business's published email and WhatsApp number first.",
        'scouts' => "On it, $by: I search for businesses that publish an email.",
        'director' => "Heard, $by: your standing goes into my orders this morning.",
        'social' => "On it, $by.",
        default => '',
    };
}

/**
 * The heads check their goals (the morning run, before the Director's meeting): the result is stored, a head that is behind asks the head that holds it up
 * on the team board (once; the same ask is not repeated), and an ask comes down when the goal is met again. Returns the goals.
 */
function pm_heads_check(string $brand): array
{
    $h = pm_heads_goals($brand);
    $old = (array)(pm_load('heads', fn() => [])[$brand]['goals'] ?? []);
    foreach ($h['goals'] as $dept => $x) {
        $key = 'ask:' . $dept;
        if ($x['ok'] || $x['ask_to'] === '' || $x['ask'] === '') {
            if (($old[$dept]['ask_to'] ?? '') !== '') { // met again (or nobody to ask): the ask comes down
                pm_tb_take($brand, $key);
                function_exists('pm_talk') && $x['ok'] && pm_talk($brand, $dept, 'Back on track: ' . $x['have'] . '. Thank you.', (string)$old[$dept]['ask_to']);
            }
            continue;
        }
        if (($old[$dept]['ask'] ?? '') === $x['ask'] && pm_tb_has($brand, $key)) {
            continue; // already asked, still up
        }
        pm_tb_post($brand, $dept, $x['ask_to'], $x['ask'], $key);
        if (($ans = pm_heads_answer($x['ask_to'], $dept)) !== '' && function_exists('pm_talk')) {
            pm_talk($brand, $x['ask_to'], $ans, $dept);
        }
    }
    pm_update('heads', function (array $all) use ($brand, $h) {
        $all[$brand] = $h;
        return $all;
    }, fn() => []);
    return $h;
}

/** The heads that are asking this one for help right now. */
function pm_heads_asked(string $brand, string $to): array
{
    return array_keys(array_filter((array)(pm_load('heads', fn() => [])[$brand]['goals'] ?? []), fn($x) => !$x['ok'] && ($x['ask_to'] ?? '') === $to));
}

/** One head's goal for its own instructions: '' when there is none stored. */
function pm_heads_goal_line(string $brand, string $dept): string
{
    $x = (array)(pm_load('heads', fn() => [])[$brand]['goals'][$dept] ?? []);
    return $x ? 'Your goal: ' . $x['goal'] . ' (now ' . $x['have'] . ($x['ok'] ? ', on track' : ', behind') . ').' : '';
}

/** Every head's standing in one line, for the Director's morning meeting. */
function pm_heads_line(string $brand): string
{
    $h = (array)(pm_load('heads', fn() => [])[$brand] ?? []);
    $rows = [];
    foreach ((array)($h['goals'] ?? []) as $dept => $x) {
        $dept !== 'director' && $rows[] = $dept . ': ' . ($x['ok'] ? 'on track' : 'BEHIND') . ' (' . $x['goal'] . '; ' . $x['have'] . ')' . ($x['ask_to'] !== '' ? ', asked ' . $x['ask_to'] : '');
    }
    return $rows ? trim(($h['line'] ?? '') . ' ' . implode(' | ', $rows)) : '';
}
