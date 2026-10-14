<?php
/**
 * Today's board: the Overview, put together by the team. At the end of the morning run every department head reports to the Director what they did and
 * what is waiting for the owner; the Director (who keeps the board) checks it against what we really have (the run happened, the Page and mailbox are
 * connected, nothing is stuck in the Outbox, nobody is left unanswered) and puts the owner's few things first. Written by code from stored data: no AI.
 * The reports go into the agents' conversation (each head to the Director), so the Audit shows the stand-up. The Overview rebuilds it quietly when
 * it is over an hour old, so it always shows what we have.
 * Data: data/board.json {brand: {at, standup (when the heads last reported), headline, you[[text, href, urgent, dept]], reports[[dept, text, href]], checks[[text, href]]}}.
 */

/** Every head's report and the Director's checks, from what is stored now. */
function pm_board_build(string $brand): array
{
    $was = pm_brand();
    pm_brand_set($brand);
    try {
        $today = date('Y-m-d');
        $L = array_filter(pm_leads(), fn($l) => ($l['brand'] ?? 'promanaged') === $brand);
        $mail = fn($l) => (bool)filter_var($l['email'] ?? '', FILTER_VALIDATE_EMAIL) && !(!empty($l['email_bad']) && strcasecmp((string)$l['email_bad'], (string)$l['email']) === 0);
        $cnt = fn(callable $f) => count(array_filter($L, $f));
        $n = fn(int $x, string $one, string $many) => $x . ' ' . ($x === 1 ? $one : $many);
        $isPost = fn($l) => !empty($l['listen']);
        $ready = $cnt(fn($l) => $mail($l) && empty($l['approved_at']) && ($l['status'] ?? '') !== 'optout'
            && ((in_array($l['status'] ?? '', ['new', 'qualified', 'drafted'], true) && empty($l['sent']) && !empty($l['drafts']['email_body'])) || (($l['status'] ?? '') === 'contacted' && !empty($l['followup_draft']['email_body']))));
        $firstReady = $cnt(fn($l) => $mail($l) && empty($l['approved_at']) && in_array($l['status'] ?? '', ['new', 'qualified', 'drafted'], true) && empty($l['sent']) && !empty($l['drafts']['email_body']));
        $waiting = array_filter($L, fn($l) => ($l['status'] ?? '') !== 'optout' && (($l['status'] ?? '') === 'replied' || !empty($l['awaiting_reply_since'])));
        $answers = count(array_filter($waiting, fn($l) => !empty($l['reply_draft']['body'])));
        $people = $cnt(fn($l) => $isPost($l) && ($l['status'] ?? '') === 'new');
        $added = $cnt(fn($l) => !$isPost($l) && str_starts_with((string)($l['created'] ?? ''), $today));
        $studied = $cnt(fn($l) => str_starts_with((string)($l['research']['at'] ?? ''), $today));
        $sat = array_filter($L, fn($l) => str_starts_with((string)($l['sitdown']['at'] ?? ''), $today));
        $noMail = $cnt(fn($l) => !$isPost($l) && !$mail($l) && in_array($l['status'] ?? '', ['new', 'qualified', 'drafted'], true));
        $queued = $cnt(fn($l) => !empty($l['approved_at']) && empty($l['sent']));
        $sent = pm_sent_today($L);
        $outbox = function_exists('pm_ml_outbox') ? count(pm_ml_outbox($brand)) : 0;
        $dir = function_exists('pm_director_brief') ? pm_director_brief(false, false) : [];
        $listen = function_exists('pm_listen_state') ? (array)(pm_listen_state($brand)['day'] ?? []) : [];
        $page = (array)($listen['page'] ?? []);
        $pageWait = (int)($page['comments'] ?? 0) + (int)($page['messages'] ?? 0);
        $postsWait = function_exists('pm_social_posts') ? count(array_filter(pm_social_posts(), fn($p) => ($p['brand'] ?? 'promanaged') === $brand && in_array($p['status'] ?? '', ['draft', 'review'], true))) : 0;
        $fbReady = function_exists('pm_social_cfg') && pm_social_cfg($brand)['ready'];
        $run = pm_run_state();
        $ranToday = str_starts_with((string)($run['finished'] ?? $run['started'] ?? ''), $today);

        // what each head tells the Director
        $rep = [];
        $say = function (string $dept, string $text, string $href = '') use (&$rep) {
            $rep[] = [$dept, $text, $href];
        };
        $say('scouts', $added ? 'I found ' . $n($added, 'new business', 'new businesses') . ' today.' : 'No new businesses today.', '?tab=agents&sort=new');
        $say('research', $studied ? 'I studied ' . $n($studied, 'lead', 'leads') . ' today: the business, its news, the owner and contacts.' : 'Nothing new to study today.', '?tab=agents');
        $noMail && $say('contact', $n($noMail, 'lead still has', 'leads still have') . ' no email; I keep looking.', '?tab=agents&status=noemail');
        if ($sat) { // the sit-downs: how many leads, and what was decided
            $acts = array_count_values(array_map(fn($l) => (string)($l['sitdown']['action'] ?? ''), $sat));
            $say('director', 'We sat down over ' . $n(count($sat), 'lead', 'leads') . ' and decided: ' . implode(', ', array_map(fn($a, $c) => strtolower(PM_SD_ACTIONS[$a] ?? $a) . ' (' . $c . ')', array_keys($acts), $acts)) . '.', '?tab=agents');
        }
        $say('writers', $firstReady ? $n($firstReady, 'first email is', 'first emails are') . ' written and ready for you to send.' : 'No first emails waiting.', '?tab=agents&status=ready');
        $fu = $ready - $firstReady;
        $fu > 0 && $say('followup', $n($fu, 'follow-up is', 'follow-ups are') . ' ready for you to send.', '?tab=agents&status=followups');
        $say('replies', $waiting ? $n(count($waiting), 'person wrote', 'people wrote') . ' back and ' . ($answers ? $n($answers, 'answer is', 'answers are') . ' drafted for you.' : 'waits for your answer.') : 'Nobody is waiting for an answer.', '?tab=agents&status=awaiting');
        $say('social', ($fbReady ? 'Our Page: ' . ($pageWait ? $n($pageWait, 'person waits', 'people wait') . ' for an answer' : 'nobody waiting') . '. ' : 'Our Facebook Page is not connected, so I cannot read it. ')
            . ($people ? $n($people, 'person', 'people') . ' asking for what we offer, with replies to paste. ' : '') . ($postsWait ? $n($postsWait, 'post waits', 'posts wait') . ' for your approval.' : ''), $people ? '?tab=agents&status=people' : '?tab=social');
        $say('sender', 'Sent ' . $sent . ' today' . ($queued ? ', ' . $queued . ' approved and queued' : '') . ($outbox ? '; ' . $n($outbox, 'email is', 'emails are') . ' stuck in the Outbox.' : '.'), $outbox ? '?tab=agents#outbox' : '?tab=agents');

        // what the owner does today, most urgent first
        $you = [];
        $waiting && $you[] = ['Answer ' . $n(count($waiting), 'person who wrote', 'people who wrote') . ' back', '?tab=agents&status=awaiting', true, 'replies'];
        $pageWait && $you[] = ['Answer ' . $n($pageWait, 'person', 'people') . ' on our Page', '?tab=social&view=inbox', true, 'social'];
        $outbox && $you[] = ['Fix ' . $n($outbox, 'email', 'emails') . ' in the Outbox', '?tab=agents#outbox', true, 'sender'];
        $ready && $you[] = ['Read and send ' . $n($ready, 'email', 'emails'), '?tab=agents&status=ready', false, 'writers'];
        $people && $you[] = ['Paste ' . $n($people, 'reply', 'replies') . ' under public posts', '?tab=agents&status=people', false, 'social'];
        $postsWait && $you[] = ['Approve ' . $n($postsWait, 'post', 'posts'), '?tab=social', false, 'social'];

        // the Director checks the board against what we have
        $chk = [];
        !$ranToday && (int)date('G') >= 9 && $chk[] = ['The morning run has not happened today, so the reports are from the last one. Check the scheduler.', '?tab=settings&section=health'];
        !$fbReady && $chk[] = ['Connect the Facebook Page so Social can read comments and messages and post.', '?tab=settings&section=connections'];
        !pm_agents_ready() && $chk[] = ['Add an AI key: the team cannot write without one.', '?tab=settings&section=connections'];
        ($dir['date'] ?? '') !== '' && ($dir['date'] ?? '') < date('Y-m-d', strtotime('-2 days')) && $chk[] = ['My strategy is ' . max(2, (int)((time() - strtotime((string)$dir['date'])) / 86400)) . ' days old.', '?tab=home#brief'];
        ($listen['date'] ?? '') !== $today && $ranToday && $chk[] = ['Social has not held today\'s huddle yet.', '?tab=social'];

        $headline = $you ? count($you) . ' thing' . (count($you) === 1 ? '' : 's') . ' for you today: ' . strtolower(implode(', ', array_column(array_slice($you, 0, 3), 0))) . '.'
            : 'Nothing needs you right now: the team is working.';
        return ['at' => date('Y-m-d H:i'), 'headline' => $headline, 'you' => $you, 'reports' => $rep, 'checks' => $chk, 'ran_today' => $ranToday];
    } finally {
        pm_brand_set($was);
    }
}

/** Builds and keeps today's board. $standup: the heads report to the Director in the agents' conversation (the morning run does this once). */
function pm_board_refresh(string $brand, bool $standup = false): array
{
    $old = (array)(pm_load('board', fn() => [])[$brand] ?? []);
    $b = pm_board_build($brand) + ['standup' => $standup ? date('H:i') : (substr((string)($old['at'] ?? ''), 0, 10) === date('Y-m-d') ? (string)($old['standup'] ?? '') : '')];
    if ($standup && function_exists('pm_talk')) {
        foreach ($b['reports'] as [$dept, $text]) {
            $dept !== 'director' && pm_talk($brand, $dept, $text, 'director');
        }
        pm_talk($brand, 'director', 'Today\'s board is up. ' . $b['headline'] . ($b['checks'] ? ' To check: ' . implode(' ', array_column($b['checks'], 0)) : ''));
    }
    pm_update('board', function (array $all) use ($brand, $b) {
        $all[$brand] = $b;
        return $all;
    }, fn() => []);
    return $b;
}

/** Today's board for the Overview: the stored one, rebuilt (quietly, no AI) when it is over an hour old or from another day. */
function pm_board(string $brand): array
{
    $b = (array)(pm_load('board', fn() => [])[$brand] ?? []);
    if (empty($b['at']) || strtotime((string)$b['at']) < time() - 3600 || substr((string)$b['at'], 0, 10) !== date('Y-m-d')) {
        try {
            $b = pm_board_refresh($brand);
        } catch (Throwable) {
        }
    }
    return $b;
}
