<?php
/**
 * The Agents screen. Included by index.php, so it shares its variables ($csrf, $tpl, $settings ...).
 * Layout, top to bottom: find-a-business, run + progress, today's tasks (one closed line), leads, then a "More" fold. The numbers and the Director's strategy live on the Overview.
 */
$bsel = (string)($_GET['brand'] ?? ($_SESSION['abrand'] ?? 'promanaged'));
$_SESSION['abrand'] = pm_brand_norm($bsel);
pm_brand_set($_SESSION['abrand']);
$bname = pm_brand_title(pm_settings(), pm_brand());
$acfg = pm_agents_config();
$readied = function_exists('pm_dm_ready_all') ? pm_dm_ready_all() : 0; // every lead with an email has its first email ready (default message + personal line, no AI)
function_exists('pm_followup_heal') && pm_followup_heal(pm_brand()); // and everyone we wrote to who has not answered has the next follow-up ready (no AI)
$synced = function_exists('pm_ml_autosync') ? pm_ml_autosync() : 0; // the Sent folders are read at most every 5 minutes (no AI): a lead emailed from the other copy is not in Active
$leadsAll = array_filter(pm_leads(), fn($l) => ($l['brand'] ?? 'promanaged') === pm_brand());
$run = pm_run_state();
$running = ($run['state'] ?? '') === 'running';
$plan = pm_daily_plan($leadsAll, $acfg);
$filter = (string)($_GET['status'] ?? 'active');
$ftype = (string)($_GET['type'] ?? '');
$fq = trim((string)($_GET['q'] ?? ''));
$fcity = trim((string)($_GET['city'] ?? ''));
$fhas = (string)($_GET['has'] ?? '');
$fmin = (int)($_GET['min'] ?? 0);
$fsort = (string)($_GET['sort'] ?? '');
$ffolder = (string)($_GET['folder'] ?? '');
$folders = pm_folders(pm_brand(), $leadsAll);
if (isset($_GET['per'])) { $_SESSION['leads_per'] = (int)$_GET['per']; } // the page size is remembered
$fper = in_array((int)($_SESSION['leads_per'] ?? 25), [10, 25, 50, 100, 200], true) ? (int)($_SESSION['leads_per'] ?? 25) : 25;
$leadHas = ['email' => 'Has an email', 'noemail' => 'No email yet', 'name' => 'Owner name known', 'noname' => 'No owner name yet', 'personal' => 'Has a personal opening', 'research' => 'Researched', 'noresearch' => 'Not researched yet', 'approved' => 'In the send queue'];
$hasOk = fn($l) => match ($fhas) {
    'email' => (bool)filter_var($l['email'] ?? '', FILTER_VALIDATE_EMAIL), 'noemail' => !filter_var($l['email'] ?? '', FILTER_VALIDATE_EMAIL),
    'name' => trim((string)($l['contact'] ?? '')) !== '', 'noname' => trim((string)($l['contact'] ?? '')) === '', 'personal' => pm_lead_personal($l) !== '',
    'research' => !empty($l['research']), 'noresearch' => empty($l['research']), 'approved' => !empty($l['approved_at']) && empty($l['sent']), default => true,
};
$fq2 = function (array $set = []) use ($ftype, $fq, $fcity, $fhas, $fmin, $fsort, $ffolder): string { // the filters, for a link
    $out = '';
    foreach (array_replace(['type' => $ftype, 'q' => $fq, 'city' => $fcity, 'has' => $fhas, 'min' => $fmin, 'sort' => $fsort, 'folder' => $ffolder], $set) as $k => $v) {
        $out .= $v === '' || $v === 0 ? '' : '&' . $k . '=' . urlencode((string)$v);
    }
    return $out;
};
$focusId = isset($leadsAll[(string)($_GET['lead'] ?? '')]) ? (string)$_GET['lead'] : '';
$who = (string)($GLOBALS['PM_WHO'] ?? '');
$team = array_values(array_filter((array)($settings['team'] ?? [])));
$waiting = fn($l) => $l['status'] !== 'optout' && ($l['status'] === 'replied' || !empty($l['awaiting_reply_since']));
$gaps = fn($l) => in_array($l['status'], ['new', 'qualified', 'drafted'], true) && empty($l['sent']) && empty($l['listen']) && pm_approach($l)['ready'] < 70;
$people = fn($l) => !empty($l['listen']) && in_array($l['status'], ['new', 'contacted', 'replied'], true); // found by Thandi on a public post: answered there // not written to yet, and too little known for a good approach
$fuDue = array_flip(array_map('strval', array_keys(pm_followup_due($leadsAll, $acfg, false))));
$waitAns = fn($l) => $l['status'] === 'contacted' && (!empty($l['followup_draft']) || isset($fuDue[(string)$l['id']]) || pm_followup_open($l, ['max_followups' => 99] + $acfg)); // we wrote, they have not answered: their follow-up is prepared, due or not
$todo = fn($l) => in_array($l['status'], ['new', 'qualified', 'drafted', 'replied', 'proposal'], true) || $waiting($l) || $waitAns($l); // Active: everyone still in play, including those we wait on
$hasMail = fn($l) => (bool)filter_var($l['email'] ?? '', FILTER_VALIDATE_EMAIL) && !(!empty($l['email_bad']) && strcasecmp((string)$l['email_bad'], (string)$l['email']) === 0);
$readySend = fn($l) => $hasMail($l) && empty($l['approved_at']) && $l['status'] !== 'optout'
    && ((in_array($l['status'], ['new', 'qualified', 'drafted'], true) && empty($l['sent']) && !empty($l['drafts']['email_body'])) || ($l['status'] === 'contacted' && !empty($l['followup_draft']['email_body']))); // Ready to send: an email address and a message, nothing in the way
$inStatus = fn($l, $f) => $f === 'people' ? $people($l) : ($f === 'all' ? true : ($f === 'mine' ? (($l['owner'] ?? '') === $who && (!in_array($l['status'], ['won', 'lost', 'optout'], true) || $waiting($l))) : ($f === 'awaiting' ? $waiting($l)
    : ($f === 'ready' ? $readySend($l) : ($f === 'noemail' ? (!$hasMail($l) && empty($l['listen']) && !in_array($l['status'], ['won', 'lost', 'optout'], true))
    : ($f === 'followups' ? $waitAns($l) : ($f === 'closed' ? in_array($l['status'], ['lost', 'optout'], true)
    : ($f === 'gaps' ? $gaps($l) : ($f === 'active' ? $todo($l) : $l['status'] === $f)))))))));
$inSearch = fn($l) => ($fq === '' || mb_stripos($l['name'] . ' ' . $l['city'] . ' ' . ($l['contact'] ?? '') . ' ' . ($l['type'] ?? '') . ' ' . ($l['email'] ?? ''), $fq) !== false)
    && ($fcity === '' || strcasecmp(trim((string)($l['city'] ?? '')), $fcity) === 0) && $hasOk($l) && (int)($l['score'] ?? 0) >= $fmin
    && ($ffolder === '' || in_array($ffolder, (array)($l['folders'] ?? []), true));
$cities = array_count_values(array_filter(array_map(fn($l) => trim((string)($l['city'] ?? '')), $leadsAll)));
ksort($cities);
// business-type dropdown: similar types grouped (hotel, lodge, resort... -> Hotels & lodges), counted inside the chosen status
$types = [];
foreach ($leadsAll as $l) {
    if ($inStatus($l, $filter) && $inSearch($l)) { $g = pm_lead_group($l); $types[$g] = ($types[$g] ?? 0) + 1; }
}
arsort($types);
if ($ftype !== '' && !isset($types[$ftype])) { $types[$ftype] = 0; }
$base = array_filter($leadsAll, fn($l) => ($ftype === '' || pm_lead_group($l) === $ftype) && $inSearch($l)); // status counts follow the type and search
$counts = array_fill_keys(array_keys(PM_LEAD_STATUSES), 0);
foreach ($base as $l) { $counts[$l['status']] = ($counts[$l['status']] ?? 0) + 1; }
$counts['active'] = count(array_filter($base, fn($l) => $inStatus($l, 'active')));
$counts['awaiting'] = count(array_filter($base, fn($l) => $waiting($l)));
$counts['gaps'] = count(array_filter($base, $gaps));
$counts['followups'] = count(array_filter($base, fn($l) => $inStatus($l, 'followups')));
$counts['ready'] = count(array_filter($base, $readySend));
$counts['noemail'] = count(array_filter($base, fn($l) => $inStatus($l, 'noemail')));
$counts['people'] = count(array_filter($base, $people));
$counts['closed'] = count(array_filter($base, fn($l) => $inStatus($l, 'closed')));
$counts['all'] = count($base);
$counts['mine'] = $who === '' ? 0 : count(array_filter($base, fn($l) => $inStatus($l, 'mine')));
$shown = array_filter($base, fn($l) => $inStatus($l, $filter));
if ($focusId !== '') { $shown = [$focusId => $leadsAll[$focusId]]; }
usort($shown, match ($fsort) {
    'new' => fn($a, $b) => strcmp((string)($b['created'] ?? ''), (string)($a['created'] ?? '')),
    'name' => fn($a, $b) => strcasecmp((string)$a['name'], (string)$b['name']),
    'city' => fn($a, $b) => [strtolower((string)$a['city']), -(int)($a['score'] ?? 0)] <=> [strtolower((string)$b['city']), -(int)($b['score'] ?? 0)],
    'ready' => fn($a, $b) => pm_approach($b)['ready'] <=> pm_approach($a)['ready'],
    default => fn($a, $b) => [$waiting($b), $b['score'] ?? 0] <=> [$waiting($a), $a['score'] ?? 0],
});
$atPos = array_search((string)($_GET['at'] ?? ''), array_column($shown, 'id'), true); // ?at=<id>: open the page that holds this lead
[$shown, $pager] = pm_ui_page($shown, $fper, 'p', $atPos === false ? 0 : intdiv((int)$atPos, $fper) + 1);
$unmatched = pm_inbox_unmatched_list();
$hasApprove = function_exists('pm_approve_for_send');
$approvable = fn($l) => $hasApprove && empty($l['approved_at']) && filter_var($l['email'] ?? '', FILTER_VALIDATE_EMAIL)
    && ((in_array($l['status'], ['drafted', 'qualified'], true) && empty($l['sent']) && !empty($l['drafts']['email_body'])) || ($l['status'] === 'contacted' && !empty($l['followup_draft']['email_body']))); // a first email or a follow-up ready to go
$nApprovable = count(array_filter($shown, $approvable));
$fuOpen = fn($l) => pm_followup_open($l, ['max_followups' => 99] + $acfg); // waiting for a follow-up: Follow up sends the next one at once
$nFollow = count(array_filter($shown, $fuOpen));
$nPrepared = count(array_filter($shown, fn($l) => $fuOpen($l) && (pm_followup_ready($l) || !empty($l['followup_draft']['email_body']))));
$sentToday = pm_sent_today($leadsAll);
$agentLog = pm_load('agent_log', fn() => []);
$hid = fn($n, $v) => '<input type="hidden" name="' . $n . '" value="' . pm_h((string)$v) . '">';
$post = fn($id, $do, $extra = '') => '<input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="agents"><input type="hidden" name="do" value="' . $do . '">'
    . $hid('rs', $filter) . $hid('rt', $ftype) . $hid('rf', $fq2(['type' => ''])) . ((int)($_GET['p'] ?? 0) > 1 ? $hid('rp', (int)$_GET['p']) : '') . ($focusId !== '' ? $hid('rl', '1') : '') . ($id !== '' ? $hid('id', $id) : '') . $extra;
require_once __DIR__ . '/view_drafts.php';
$waBiz = pm_wa_biz_cfg()['ready'];
$waOn = pm_wa_on(pm_brand()); // no WhatsApp set up for this business: email only, no WhatsApp boxes or buttons
@set_time_limit(120);
$dir = pm_director_brief(false, false) + ['headline' => '', 'priorities' => [], 'focus' => [], 'drop' => [], 'experiment' => '']; // a new business has no advice yet
$ds = $dir['stats'];
$ndone = count(array_filter($plan, fn($t) => $t['done']));
?>
<?= pm_ui_head('Leads · ' . pm_h($bname), 'Find businesses, let the agents draft the messages, and send what you approve. Nothing goes out without you.') ?>
<?= pm_ui_strip('agents') ?>
<?php if ($readied): ?><div class="flash ok"><?= $readied ?> lead<?= $readied === 1 ? ' was' : 's were' ?> put in order: a lead with an email but no first email now has one ready (from your default message), and a lead that was already emailed is under Contacted.</div><?php endif; ?>
<?php if ($synced): ?><div class="flash ok"><?= $synced ?> lead<?= $synced === 1 ? '' : 's' ?> found in the Sent folder (emailed from the other copy of the app or by hand) and marked contacted.</div><?php endif; ?>
<?php if (!pm_agents_ready()): ?>
  <div class="flash err"><b>One thing to set up first.</b> Add <code><?= pm_h(pm_agents_missing_key()) ?>=...</code> to <code>.env</code>, then reload.</div>
<?php endif; ?>

<!-- 1. Find a business -->
<div class="card find">
  <form method="post" class="findrow"><?= $post('', 'lookup') ?><?= $hid('brand', pm_brand()) ?>
    <input type="text" name="biz" required data-suggest data-web="1" autocomplete="off" autocapitalize="words" spellcheck="false" placeholder="Find a business by name, <?= pm_brand() === 'travel' ? 'e.g. Mufasa Eco Lodge' : (pm_brand_is_custom(pm_brand()) ? '' : 'e.g. Sunbird Capital Hotel') ?>" aria-label="Business name">
    <input type="text" name="city" placeholder="City (optional)" aria-label="City" class="narrow">
    <button class="btn primary">Find</button>
  </form>
  <p class="hint mt-1">Start typing: it suggests businesses you already know, then ones a web search finds (those are marked, and only fill the box).</p>
</div>

<?php if (pm_brand() === 'travel'): $tmAll = pm_leads(); $tmTodo = pm_tm_rewrite_candidates($tmAll); $tmKept = pm_tm_rewrite_kept($tmAll); if ($tmTodo): ?>
<div class="card nofold" id="tmformat">
  <b>Travel Malawi's emails have a new standard format.</b> It is short and personal: who we are, what we found about that place, the directory we are building together, and the link to <?= pm_h(preg_replace('#^https?://#', '', (string)pm_tm_email_cfg()['link'])) ?>. You can change the wording in Sales &gt; Targeting and messages.
  <p class="hint mt-1"><?= count($tmTodo) ?> unsent draft<?= count($tmTodo) === 1 ? ' is' : 's are' ?> still in the old format.<?= $tmKept['approved'] + $tmKept['edited'] ? ' Emails you approved (' . (int)$tmKept['approved'] . ') or edited by hand (' . (int)$tmKept['edited'] . ') are left alone.' : '' ?> Redrafting uses about one AI call for every six emails and never sends anything.</p>
  <form method="post" class="btns"><?= $post('', 'tm_rewrite') ?><button class="btn primary" <?= pm_agents_ready() ? '' : 'disabled title="Add an AI key to .env first"' ?>>Redraft <?= count($tmTodo) ?> email<?= count($tmTodo) === 1 ? '' : 's' ?> in the new format</button></form>
</div>
<?php endif; endif; ?>
<?php $sjTodo = pm_subjects_pending(pm_leads()); if ($sjTodo): $sj1 = reset($sjTodo); ?>
<div class="card nofold" id="subjectfix">
  <b><?= count($sjTodo) ?> subject line<?= count($sjTodo) === 1 ? '' : 's' ?> need capital letters.</b> For example "<?= pm_h(mb_substr($sj1['subject'], 0, 70)) ?>" should read "<?= pm_h(mb_substr($sj1['tidy'], 0, 70)) ?>". Anything sent is always corrected, and new drafts are written this way; this puts the ones already drafted right.
  <p class="hint mt-1">Only the capitals and punctuation change, never a word. Drafts you edited yourself are left alone. Nothing is sent.<?= pm_agents_ready() ? ' The AI also checks the names code does not know.' : '' ?></p>
  <form method="post" class="btns"><?= $post('', 'fix_subjects') ?><button class="btn primary">Fix <?= count($sjTodo) ?> subject line<?= count($sjTodo) === 1 ? '' : 's' ?></button></form>
</div>
<?php endif; ?>

<!-- 2. Run the agents + progress -->
<div class="card runbar" id="runbar" data-running="<?= $running ? '1' : '0' ?>">
  <div class="runtop">
    <div>
      <b><?= $running ? 'Agents are searching' : 'Find new leads' ?></b>
      <span class="muted" id="runsub">
        <?php if ($running): ?><?= pm_h($run['phase'] ?? 'Starting') ?><?php elseif (($run['state'] ?? '') === 'done' && ($run['mode'] ?? '') === 'names'): ?>Name search <?= pm_h(date('j M, H:i', strtotime((string)($run['finished'] ?? 'now')))) ?>: <?= (int)($run['stats']['named'] ?? 0) ?> leads have an owner or manager name<?php elseif (($run['state'] ?? '') === 'done'): $st = $run['stats'] ?? []; ?>Last run <?= pm_h(date('j M, H:i', strtotime((string)($run['finished'] ?? 'now')))) ?>: <?= (int)($st['added'] ?? 0) ?> new, <?= (int)($st['qualified'] ?? 0) ?> qualified, <?= (int)($st['drafted'] ?? 0) ?> drafts<?php $studied = count(array_filter($leadsAll, fn($x) => str_starts_with((string)($x['research']['at'] ?? ''), date('Y-m-d')))); ?>, <?= $studied ?> studied today<?= !empty($st['errors']) ? ', ' . (int)$st['errors'] . ' errors' : '' ?><?php elseif (in_array($run['state'] ?? '', ['error', 'stalled'], true)): ?><?= $run['state'] === 'stalled' ? 'The last run stopped responding.' : 'The last run failed: ' . pm_h($run['msg'] ?? '') ?><?php else: ?>Scouts search the web, then qualify and draft. Nothing is sent without you.<?php endif; ?>
      </span>
    </div>
    <div class="acts">
      <?php if (pm_imap_ready()): $ir = pm_inbox_read_status(pm_brand()); ?><form method="post" id="check-replies"><?= $post('', 'check_replies') ?><button class="btn<?= $ir['age'] === null || $ir['age'] >= 3600 || $ir['err'] !== '' ? ' primary' : '' ?>" <?= $running ? 'disabled' : '' ?> title="<?= pm_h($ir['err'] !== '' ? 'The last check failed: ' . $ir['err'] : ($ir['age'] === null ? 'The inbox has never been read' : 'Inbox last read ' . pm_inbox_ago((int)$ir['age']) . ' ago')) ?>">Check replies</button></form><?php endif; ?>
      <form method="post"><input type="hidden" name="csrf" value="<?= pm_h($csrf) ?>"><input type="hidden" name="action" value="mail_log"><input type="hidden" name="do" value="sync"><input type="hidden" name="back" value="agents"><button class="btn" <?= $running ? 'disabled' : '' ?> title="Reads the Sent folders in your mailboxes and marks every lead already emailed as contacted (out of Active), filed in the folder Already contacted">Compare with the Sent folder</button></form>
      <form method="post"><?= $post('', 'find_names') ?><button class="btn" <?= $running ? 'disabled' : '' ?> title="Looks for the owner or manager of leads that have no name yet">Find owner names</button></form>
      <form method="post"><?= $post('', 'run') ?><button class="btn primary" id="runbtn" <?= $running ? 'disabled' : '' ?>><?= $running ? 'Working…' : 'Run the agents' ?></button></form>
    </div>
  </div>
  <div class="prog" id="runprog" <?= $running ? '' : 'hidden' ?>><i id="runfill" style="width:<?= (int)(100 * (int)($run['step'] ?? 0) / max(1, (int)($run['steps'] ?? 7))) ?>%"></i></div>
</div>

<?php if ($unmatched): $cands = array_filter($leadsAll, fn($x) => !empty($x['sent']) || !empty($x['last_contacted']) || !empty($x['wa_sent'])); uasort($cands, fn($a, $b) => strcasecmp($a['name'], $b['name'])); ?>
<div class="card unmatched"><h2>Unmatched replies <span class="pill warn"><?= count($unmatched) ?></span></h2>
  <p class="hint">Mail that reached your inbox from someone we could not match to a lead. Attach it to the right lead and the reply agent handles it like any reply, or dismiss it.</p>
  <?php foreach (array_slice($unmatched, 0, 10) as $u): ?>
    <div class="unm"><div><b><?= pm_h($u['name'] !== '' ? $u['name'] . ' · ' : '') ?><?= pm_h($u['from']) ?></b> <span class="muted">· <?= pm_h($u['at']) ?></span><br><b><?= pm_h($u['subject']) ?></b><br><span class="muted"><?= pm_h($u['snippet']) ?></span></div>
      <form method="post" class="inline"><?= $post('', 'attach_unmatched', $hid('uid', $u['uid'])) ?>
        <select name="to" aria-label="Lead to attach to" required><option value="">Attach to lead…</option><?php foreach (array_slice($cands, 0, 400) as $c): ?><option value="<?= pm_h($c['id']) ?>"><?= pm_h($c['name'] . ' (' . $c['city'] . ')') ?></option><?php endforeach; ?></select>
        <button class="btn small primary">Attach to lead</button>
        <button class="btn small" formnovalidate name="do" value="dismiss_unmatched">Dismiss</button></form></div>
  <?php endforeach; ?>
  <?php if (count($unmatched) > 10): ?><p class="hint">And <?= count($unmatched) - 10 ?> more: handle these first.</p><?php endif; ?>
</div>
<?php endif; ?>

<?php
$taskExtra = function (array $t) use ($leadsAll, $post): string { // a follow-up task: its draft is ready, or the AI can write it now
    if (($t['kind'] ?? '') !== 'followup' || empty($t['lead']) || !isset($leadsAll[$t['lead']])) {
        return '';
    }
    $l = $leadsAll[$t['lead']];
    if (!empty($l['followup_draft'])) {
        return ' <span class="pill hot">draft ready</span>';
    }
    if (($l['status'] ?? '') !== 'contacted' || pm_lead_email_dead($l)) {
        return '';
    }
    return (pm_agents_ready() ? '<form method="post" class="inline">' . $post($t['lead'], 'followup_ai') . '<button class="btn small" title="The Follow-up agent writes a draft for you to read; nothing is sent">Draft with AI</button></form>' : '')
        . '<form method="post" class="inline">' . $post($t['lead'], 'followup_default') . '<button class="btn small" title="The default follow-up, no AI; nothing is sent">Use default</button></form>';
};
?>

<!-- 4. Today's tasks -->
<?php if ($plan): ?>
<details class="card today"><summary><b>Today</b> · <?= $ndone ?> of <?= count($plan) ?> done <span class="muted">· <?= $sentToday ?> emails sent today (your plan: <?= (int)$acfg['send_cap'] ?>)</span></summary>
  <?php foreach (array_slice($plan, 0, 6) as $t): ?>
    <div class="task <?= $t['done'] ? 'done' : '' ?>">
      <form method="post"><?= $post('', 'plan', $hid('task', $t['id']) . ($t['done'] ? '' : $hid('done', 1))) ?><button title="Mark done" aria-label="Mark done"><?= $t['done'] ? '✓' : '' ?></button></form>
      <span><?= pm_h($t['title']) ?></span><?= $taskExtra($t) ?>
      <?php if ($t['lead'] && isset($leadsAll[$t['lead']])): ?><a class="muted open" href="?tab=agents&status=all&at=<?= pm_h(rawurlencode($t['lead'])) ?>#l<?= pm_h($t['lead']) ?>">open</a><?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if (count($plan) > 6): ?><details class="more"><summary>Show the other <?= count($plan) - 6 ?></summary>
    <?php foreach (array_slice($plan, 6) as $t): ?><div class="task <?= $t['done'] ? 'done' : '' ?>">
      <form method="post"><?= $post('', 'plan', $hid('task', $t['id']) . ($t['done'] ? '' : $hid('done', 1))) ?><button title="Mark done" aria-label="Mark done"><?= $t['done'] ? '✓' : '' ?></button></form>
      <span><?= pm_h($t['title']) ?></span><?= $taskExtra($t) ?></div><?php endforeach; ?></details><?php endif; ?>
<?php if ((int)(($dir['stats'] ?? [])['followups_to_draft'] ?? 0) > 0): ?><form method="post"><?= $post('', 'followups_ai') ?><button class="btn small primary" title="<?= pm_followup_by_default(pm_brand()) ? 'Made from the default follow-up (Sales &gt; Targeting and messages)' : 'The Follow-up agent writes them' ?> for you to read; nothing is sent">Draft <?= (int)$ds['followups_to_draft'] ?> follow-up<?= (int)$ds['followups_to_draft'] === 1 ? '' : 's' ?><?= pm_followup_by_default(pm_brand()) ? '' : ' with AI' ?></button></form><?php endif; ?>
</details>
<?php endif; ?>

<!-- 5. Leads -->
<?php if ($focusId !== ''): ?>
<div class="focusbar"><a class="btn small" href="?tab=agents&status=<?= pm_h($filter) ?><?= pm_h($fq2()) ?>&at=<?= pm_h(rawurlencode($focusId)) ?>#l<?= pm_h($focusId) ?>">← All leads</a> <span class="muted">Focused on <b><?= pm_h($leadsAll[$focusId]['name']) ?></b></span></div>
<?php else: ?>
<div class="filters">
  <?php foreach (($who !== '' ? ['mine' => 'Mine'] : []) + ['active' => 'Active', 'people' => 'People looking', 'ready' => 'Ready to send', 'awaiting' => 'Needs reply', 'noemail' => 'No email', 'gaps' => 'Missing details', 'drafted' => 'Draft ready', 'followups' => 'Follow-ups', 'contacted' => 'Contacted', 'replied' => 'Replied', 'proposal' => 'Proposal sent', 'won' => 'Won', 'closed' => 'Closed', 'all' => 'All'] as $k => $l): ?>
    <a href="?tab=agents&status=<?= $k ?><?= pm_h($fq2()) ?>" class="<?= $filter === $k ? 'on' : '' ?>"><?= $l ?> <small><?= (int)($counts[$k] ?? 0) ?></small></a>
  <?php endforeach; ?>
  <form method="get" class="typesel" data-noload="1"><input type="hidden" name="tab" value="agents"><input type="hidden" name="status" value="<?= pm_h($filter) ?>">
    <input type="search" name="q" value="<?= pm_h($fq) ?>" placeholder="Search leads" aria-label="Search leads by name, town or contact" class="narrow">
    <?php $fOn = $ftype !== '' || $fcity !== '' || $fhas !== '' || $fmin || $fsort !== '' || $ffolder !== '' || $fper !== 25; ?>
    <details class="fmore" <?= $fOn ? 'open' : '' ?>><summary class="btn small">Filters<?= $fOn ? ' · on' : '' ?></summary><span class="fmore-in">
    <select name="type" onchange="(this.form.requestSubmit || this.form.submit).call(this.form)" aria-label="Business type"><option value="">All business types</option>
      <?php foreach ($types as $tk => $tn): ?><option value="<?= pm_h($tk) ?>" <?= $ftype === $tk ? 'selected' : '' ?>><?= pm_h($tk) ?> (<?= $tn ?>)</option><?php endforeach; ?></select>
    <select name="city" onchange="(this.form.requestSubmit || this.form.submit).call(this.form)" aria-label="Town"><option value="">All towns</option>
      <?php foreach ($cities as $ck => $cn): ?><option value="<?= pm_h($ck) ?>" <?= strcasecmp($fcity, (string)$ck) === 0 ? 'selected' : '' ?>><?= pm_h($ck) ?> (<?= $cn ?>)</option><?php endforeach; ?></select>
    <select name="has" onchange="(this.form.requestSubmit || this.form.submit).call(this.form)" aria-label="What we know"><option value="">Any details</option><?php foreach ($leadHas as $hk => $hl): ?><option value="<?= $hk ?>" <?= $fhas === $hk ? 'selected' : '' ?>><?= pm_h($hl) ?></option><?php endforeach; ?></select>
    <select name="min" onchange="(this.form.requestSubmit || this.form.submit).call(this.form)" aria-label="Lowest fit score"><?php foreach ([0 => 'Any score', 60 => 'Score 60+', 75 => 'Score 75+', 85 => 'Score 85+'] as $mk => $ml): ?><option value="<?= $mk ?>" <?= $fmin === $mk ? 'selected' : '' ?>><?= $ml ?></option><?php endforeach; ?></select>
    <select name="sort" onchange="(this.form.requestSubmit || this.form.submit).call(this.form)" aria-label="Order"><?php foreach (['' => 'Best first', 'ready' => 'Most ready first', 'new' => 'Newest first', 'name' => 'Name A to Z', 'city' => 'By town'] as $sk => $sl): ?><option value="<?= $sk ?>" <?= $fsort === $sk ? 'selected' : '' ?>><?= $sl ?></option><?php endforeach; ?></select>
    <?php if ($folders): ?><select name="folder" onchange="(this.form.requestSubmit || this.form.submit).call(this.form)" aria-label="Folder"><option value="">All folders</option><?php foreach ($folders as $fk => $fn): ?><option value="<?= pm_h((string)$fk) ?>" <?= $ffolder === (string)$fk ? 'selected' : '' ?>><?= pm_h((string)$fk) ?> (<?= (int)$fn ?>)</option><?php endforeach; ?></select><?php endif; ?>
    <select name="per" onchange="(this.form.requestSubmit || this.form.submit).call(this.form)" aria-label="Leads per page"><?php foreach ([10, 25, 50, 100, 200] as $pk): ?><option value="<?= $pk ?>" <?= $fper === $pk ? 'selected' : '' ?>><?= $pk ?> a page</option><?php endforeach; ?></select></span></details>
    <?php if ($ftype !== '' || $fq !== '' || $fcity !== '' || $fhas !== '' || $fmin || $fsort !== '' || $ffolder !== ''): ?><a class="btn small" href="?tab=agents&status=<?= pm_h($filter) ?>">Clear</a><?php endif; ?></form>
</div>
<?php endif; ?>
<?php $isAdm = pm_user_is_admin(); $pickable = fn($l) => true; $nPick = count($shown); $adv = function_exists('pm_send_advice') ? pm_send_advice(pm_brand()) : ['lines' => []]; $outbox = array_values(array_filter(function_exists('pm_ml_outbox') ? pm_ml_outbox(pm_brand()) : [], fn($r) => empty($leadsAll[$r['lead'] ?? '']['approved_at']) && !(($r['kind'] ?? '') === 'first' && !empty($leadsAll[$r['lead'] ?? '']['sent'])))); // a lead queued again, or whose first email has since gone, is not shown twice ?>
<?php if ($outbox): ?>
<div class="card outbox" id="outbox"><h2>Outbox <span class="pill warn"><?= count($outbox) ?></span></h2>
  <p class="hint mt-0">Emails that did not go. A server hiccup is tried again by itself (up to 3 times); the rest wait for you. Nothing here is sent twice.</p>
  <?php foreach (array_slice($outbox, 0, 20) as $ob): ?><div class="obrow"><div><b><?= pm_h($leadsAll[$ob['lead'] ?? '']['name'] ?? $ob['to']) ?></b> <span class="muted">· <?= pm_h((string)$ob['to']) ?> · <?= pm_h(substr((string)$ob['at'], 0, 16)) ?><?= !empty($ob['tries']) ? ' · tried ' . (int)$ob['tries'] . ' more time' . ((int)$ob['tries'] === 1 ? '' : 's') : '' ?></span><br><span class="t-sm"><?= pm_h((string)$ob['subject']) ?></span><br><span class="t-sm warnt"><?= pm_h((string)($ob['err'] ?? '')) ?></span><?php if (!empty($ob['fix'])): ?><br><span class="t-sm"><b>Fix:</b> <?= pm_h((string)$ob['fix']) ?></span><?php endif; ?></div>
    <div class="btns"><form method="post" class="inline"><?= $post('', 'outbox_retry', $hid('ob', $ob['id'])) ?><button class="btn small primary">Try again</button></form><form method="post" class="inline"><?= $post('', 'outbox_discard', $hid('ob', $ob['id'])) ?><button class="btn small">Put aside</button></form><?php if (!empty($ob['lead']) && isset($leadsAll[$ob['lead']])): ?><a class="btn small ghost" href="?tab=agents&amp;lead=<?= pm_h((string)$ob['lead']) ?>">Open</a><?php endif; ?></div></div><?php endforeach; ?>
</div>
<?php endif; ?>
<?php if ($nPick): ?>
<form method="post" id="leadsel" class="selbar<?= $running ? ' locked' : '' ?>"><?= $post('', '') ?>
  <label class="tick"><input type="checkbox" data-pick-all=".lsel" aria-label="Tick every lead on this page"> All on this page</label>
  <b class="selcount" data-pick-count=".lsel">0 ticked</b>
  <?php if ($nFollow): ?><button class="btn primary" name="do" value="followup_send" data-confirm="Send the next follow-up to each ticked lead that has not answered? Each one gets the follow-up the Follow-up head prepared for its number (or the default for it), with the usual checks (STOP, bounced address, wording)." title="<?= $nPrepared ?> of the <?= $nFollow ?> leads on this page that have not answered have a follow-up prepared; the rest get the default for their number">Follow up</button><?php endif; ?>
  <?php if ($nApprovable): ?><button class="btn primary" name="do" value="send_now" data-confirm="Send the ticked emails now? No daily limit: each one still gets the usual checks (STOP, bounced address, wording) and is never sent twice." title="Sends each ticked email now, one after another">Send now</button>
  <label class="selwhen">or from <input type="datetime-local" name="send_after" aria-label="Send no earlier than (optional)"></label>
  <button class="btn" name="do" value="approve_send" title="Each ticked email goes out on its own, one at a time, in the sending hours (or from the time given)">Send in the sending hours</button><?php endif; ?>
  <span class="sel-more">
  <span class="selfolder"><input type="text" name="reason" placeholder="Not right? Say why" aria-label="Why the ticked drafts are not right" maxlength="200"><button class="btn small" name="do" value="turn_down" title="Takes the ticked drafts away, tells every Writer why, and writes them again now">Turn down &amp; rewrite</button></span>
  <span class="selfolder"><select name="move_to" aria-label="Move the ticked leads to"><option value="">Move to…</option><?php foreach (['contacted' => 'Contacted (I already emailed or called them)', 'replied' => 'Replied', 'proposal' => 'Proposal sent', 'won' => 'Won', 'lost' => 'Lost', 'optout' => 'Do not contact', 'drafted' => 'Draft ready', 'qualified' => 'Qualified'] as $mk => $ml): ?><option value="<?= $mk ?>"><?= pm_h($ml) ?></option><?php endforeach; ?></select><button class="btn small" name="do" value="move_to">Move</button></span>
  <span class="selfolder"><select name="folder_to" aria-label="Folder"><option value="">Folder…</option><?php foreach ($folders as $fk => $fn): ?><option value="<?= pm_h((string)$fk) ?>" <?= $ffolder === (string)$fk ? 'selected' : '' ?>><?= pm_h((string)$fk) ?></option><?php endforeach; ?></select><input type="text" name="folder_new" placeholder="or a new folder" aria-label="New folder name" maxlength="40"><button class="btn small" name="do" value="folder_add">Put in folder</button><?php if ($folders): ?><button class="btn small ghost" name="do" value="folder_remove">Take out</button><?php endif; ?></span>
  <?php if ($isAdm): ?><button class="btn small danger" name="do" value="delete_ticked" data-confirm="Delete the ticked leads? This cannot be undone.">Delete ticked</button><?php endif; ?>
  </span><span class="sel-tip hint">Tick leads to move, file, rewrite or delete them.</span>
  <?php if ($adv['lines']): ?><span class="hint advice"><b>Advice<?= !empty($adv['ai']) ? '' : ' from the numbers' ?>:</b> <?= pm_h(implode(' ', array_slice($adv['lines'], 0, 2))) ?> You decide: nothing here stops a send.</span><?php endif; ?>
  <span class="hint"><?= $nApprovable ? $nApprovable . ' email' . ($nApprovable === 1 ? '' : 's') . ' on this page can be sent. Read, tick, then <b>Send now</b>, or let them go out one at a time in the <a href="?tab=targeting#sendschedule">sending hours</a>.' : 'Nothing on this page is ready to send yet.' ?></span>
</form>
<?php endif; ?>
<?php if ($hasApprove && function_exists('pm_view_send_queue') && count(array_filter($leadsAll, fn($x) => !empty($x['approved_at'])))) { pm_view_send_queue(pm_leads()); } ?>
<?php if (!$shown && ($ftype !== '' || $fq !== '' || $fcity !== '' || $fhas !== '' || $fmin || $ffolder !== '')): ?><div class="card empty">No <?= pm_h($ftype ?: 'leads') ?><?= $fq !== '' ? ' matching "' . pm_h($fq) . '"' : '' ?> in this list. <a href="?tab=agents&status=all<?= $ftype !== '' ? '&type=' . urlencode($ftype) : '' ?><?= $fq !== '' ? '&q=' . urlencode($fq) : '' ?>">Look in All</a></div>
<?php elseif (!$shown): ?><div class="card empty">No leads here yet. <?= $filter === 'active' ? 'Press <b>Run the agents</b> or find a business by name.' : '' ?></div><?php endif; ?>
<?= $pager ?>
<?php if ($running): ?><p class="flash runlock" id="runlock">The agents are working: sending, research and drafting on the leads wait until they finish, so two jobs never clash. The page unlocks by itself.</p><?php endif; ?>
<div class="leadlist<?= $running ? ' locked' : '' ?><?= $fper >= 50 ? ' dense' : '' ?>">
<?php foreach ($shown as $l):
    $id = $l['id'];
    $fuReady = $l['status'] === 'contacted' && empty($l['followup_draft']) ? pm_followup_ready($l) : null; // written ahead by the Follow-up head: shown and editable, sent by Follow up
    $which = ($l['status'] === 'contacted' && (!empty($l['followup_draft']) || $fuReady)) ? 'followup' : 'first';
    $dr = $which === 'followup' ? pm_followup_for_send($l) : ($l['drafts'] ?? []); // as it will go: "I wrote to you yesterday" from the real date
    $canMail = !empty($dr['email_body']) && filter_var($l['email'] ?? '', FILTER_VALIDATE_EMAIL) && $l['status'] !== 'optout';
    $wa = $waOn && !empty($dr['whatsapp']) && ($l['status'] ?? '') !== 'optout' ? pm_wa_link($l, $dr['whatsapp']) : ''; // nobody who said STOP gets a WhatsApp button
    $hasReply = !empty($l['reply_draft']);
    $sentFirst = $which === 'first' && !empty($l['sent']); // the first email went: its panel folds away and is not offered for sending again
?>
<details class="lead" id="l<?= pm_h($id) ?>" <?= (($which === 'followup' && !$fuReady) || $waiting($l) || $focusId === $id) ? 'open' : '' ?>>
  <summary>
    <?php if ($pickable($l)): ?><label class="lpick" onclick="event.stopPropagation()" title="<?= $approvable($l) ? 'Tick to send this email' : ($fuOpen($l) ? 'Tick to follow up' : 'Tick to move, file or delete') ?>"><input type="checkbox" class="lsel" form="leadsel" name="ids[]" value="<?= pm_h($id) ?>" aria-label="Tick <?= pm_h($l['name']) ?>"></label><?php else: ?><span class="lpick none"></span><?php endif; ?>
    <span class="who"><b><?= pm_h($l['name']) ?></b><small><?= pm_h($l['type']) ?> · <?= pm_h($l['city']) ?></small></span>
    <span class="tags">
      <?= pm_pg_chip($l) ?><?php if ($hasReply): ?><span class="pill warn">reply waiting</span><?php elseif ($waiting($l)): ?><span class="pill warn">they wrote</span><?php elseif ($l['status'] === 'contacted' && $waitAns($l)): $fuOn = pm_followup_due_on($l, ['max_followups' => 99] + $acfg); $fuNow = isset($fuDue[(string)$id]) || !empty($l['followup_draft']); ?><span class="pill <?= $fuNow ? 'warn' : '' ?>" title="<?= $which === 'followup' ? 'The follow-up is written: open the lead to read it' : 'The Follow-up head prepares it' ?>">follow-up <?= pm_followup_nth($l) ?> <?= $fuNow ? 'due now' : ($fuOn !== '' ? 'on ' . pm_h(date('D j M', strtotime($fuOn))) : 'prepared') ?></span><?php endif; ?>
      <?php foreach (pm_lead_open_drafts($l) as $od): ?><span class="pill warn"><?= ['winback' => 'win-back ready', 'postsign' => 'thank-you asks ready', 'reverify' => 'contact re-checked'][$od] ?></span><?php endforeach; ?>
      <?php if (!empty($l['snooze_until']) && $l['snooze_until'] >= date('Y-m-d')): ?><span class="pill" title="No follow-ups until then">paused to <?= pm_h(date('j M', strtotime($l['snooze_until']))) ?></span><?php endif; ?>
      <?php if (!empty($l['outside'])): ?><span class="pill warn" title="Found in the mailbox: we were already in touch outside the app (last <?= pm_h((string)$l['outside']['last']) ?>), so it gets no cold email">in touch outside the app</span><?php endif; ?>
      <?php if (!empty($l['email_bad'])): ?><span class="pill bad" title="<?= pm_h((string)$l['email_bad'] . ' bounced' . (!empty($l['bounce']['why']) ? ': ' . $l['bounce']['why'] : '') . (!empty($l['bounced_at']) ? ' (' . $l['bounced_at'] . ')' : '') . '. No more emails go to it; find another contact.') ?>">email bounced<?= !empty($l['bounce']['kind']) && $l['bounce']['kind'] === 'blocked' ? ' (blocked)' : '' ?></span><?php endif; ?>
      <?php $apx = pm_approach($l); $gapOpen = in_array($l['status'] ?? '', ['new', 'qualified', 'drafted', 'contacted'], true); ?>
      <?php if (!empty($l['view_count'])): ?><span class="pill hot" title="They opened the proposal page">opened proposal</span><?php endif; ?>
      <?php if (!empty($l['approved_at'])): ?><span class="pill hot"><?= pm_h(function_exists('pm_approved_eta') ? (pm_approved_eta($l) ?: 'approved') : 'approved') ?></span><?php endif; ?>
      <?php if ($l['status'] === 'lost' && !empty($l['lost_reason'])): ?><span class="pill"><?= pm_h(pm_lost_reasons()[$l['lost_reason']] ?? $l['lost_reason']) ?></span><?php endif; ?>
      <?php if (!empty($l['proposal_ai'])): ?><span class="pill hot">proposal ready</span><?php endif; ?>
      <?= pm_lead_touch_chip($l) ?>
      <?php if (!empty($l['owner'])): ?><span class="pill" title="Owner of this lead"><?= pm_h($l['owner']) ?></span><?php endif; ?>
      <?php foreach ((array)($l['folders'] ?? []) as $lf): ?><a class="pill folder" href="?tab=agents&amp;status=all&amp;folder=<?= pm_h(rawurlencode((string)$lf)) ?>" onclick="event.stopPropagation()" title="Show this folder">▸ <?= pm_h((string)$lf) ?></a><?php endforeach; ?>
      <?php if (!empty($l['sitdown']['action']) && strtotime((string)$l['sitdown']['at']) > strtotime('-3 days')): ?><span class="pill <?= in_array($l['sitdown']['action'], ['send', 'call'], true) ? 'hot' : '' ?>" title="<?= pm_h((string)($l['sitdown']['angle'] ?: $l['sitdown']['why'])) ?>">Team: <?= pm_h(strtolower(PM_SD_ACTIONS[$l['sitdown']['action']] ?? ($l['sitdown']['action'] === 'reply' ? 'Answer them' : ''))) ?></span><?php endif; ?>
      <span class="fixcols"><?php /* always last and the same width, so they line up down the list */ ?>
        <span class="pill c-ready <?= $gapOpen ? ($apx['ready'] >= 80 ? 'ok' : ($apx['ready'] < 50 ? 'warn' : '')) : 'blank' ?>"<?= $gapOpen ? ' title="' . pm_h($apx['missing'] ? 'Missing: ' . implode(', ', $apx['missing']) : 'Everything a good approach needs is there') . '"' : '' ?>><?= $gapOpen ? $apx['ready'] . '% ready' : '' ?></span>
        <span class="pill c-score <?= ($l['score'] ?? 0) >= 70 ? 'hot' : '' ?>" title="How good a fit, out of 100"><?= (int)($l['score'] ?? 0) ?></span>
        <span class="pill c-status"><?= pm_h(PM_LEAD_STATUSES[$l['status']] ?? $l['status']) ?></span>
      </span>
    </span>
  </summary>
  <div class="body">
    <?php
      $lastIn = null; // their latest message: with a reply waiting it sits at the top, next to our answer
      foreach (array_reverse((array)($l['thread'] ?? []), true) as $ti => $tm) { if (($tm['dir'] ?? '') === 'in') { $lastIn = $ti; break; } }
      $convoTop = $hasReply && $lastIn !== null;
      $sdReply = !empty($l['sitdown']) && ($l['sitdown']['kind'] ?? '') === 'reply' ? $l['sitdown'] : null;
    ?>
    <?= !$convoTop && !in_array($l['status'], ['won', 'lost', 'optout'], true) && empty($l['listen']) ? pm_approach_html($l, $post, $hid) : '' ?>
    <div class="cols">
      <div class="lc-col">
        <?php if (!empty($l['listen'])): $lz = $l['listen']; ?>
        <section class="lc"><h4 class="lc-h">What they asked for <span class="muted">· found by <?= pm_h(pm_agent_first('social')) ?> on <?= pm_h((string)$lz['platform']) ?><?= $lz['posted'] !== '' ? ', posted ' . pm_h(date('j M', strtotime((string)$lz['posted']))) : '' ?></span></h4>
          <p class="why">“<?= pm_h((string)$lz['wants']) ?>”</p>
          <p class="hint"><b>Our reply, to paste under their post:</b></p><p class="hook"><?= nl2br(pm_h((string)$lz['reply'])) ?></p>
          <p class="hint">Open the post, paste the reply and press "I replied". Facebook lets no app answer under other people's posts, so this one step is yours. Nothing else about them is looked up.</p>
        </section>
        <?php endif; ?>
        <section class="lc"><h4 class="lc-h"><?= empty($l['listen']) ? 'Their business' : 'Who they are' ?></h4>
        <?php if (!empty($l['contact'])): ?><p class="person"><b><?= pm_h($l['contact']) ?></b><?= !empty($l['contact_title']) ? ' · ' . pm_h($l['contact_title']) : '' ?></p><?php endif; ?>
        <?php if (!empty($l['pitch_response'])): $pr = $l['pitch_response']; ?><p class="hint warnt">On the proposal page they chose "<?= $pr['act'] === 'yes' ? 'Yes, send me the agreement' : 'I have a question' ?>"<?= $pr['msg'] !== '' ? ': ' . pm_h($pr['msg']) : '' ?>.</p><?php endif; ?>
        <?php if (!empty($l['view_count'])): ?><p class="hint">Opened the proposal <?= (int)$l['view_count'] ?> time<?= (int)$l['view_count'] === 1 ? '' : 's' ?>, last on <?= pm_h(date('j M, H:i', strtotime((string)($l['last_viewed_at'] ?? $l['viewed_at'])))) ?>.</p><?php endif; ?>
        <?php if (!empty($l['reason']) || !empty($l['pain'])): ?><p class="why"><?= pm_h($l['reason'] ?? '') ?><?php if (!empty($l['pain'])): ?> <b>Lead with:</b> <?= pm_h($l['pain']) ?><?php endif; ?></p><?php endif; ?>
        <?php foreach (array_slice((array)($l['evidence'] ?? []), 0, 3) as $e): ?><p class="hint ev">• <?= preg_replace('#(https?://[^\s<]+)#', '<a href="$1" target="_blank" rel="noopener noreferrer">source</a>', pm_h((string)$e)) ?></p><?php endforeach; ?>
        <p class="hint"><?= pm_h(implode(' · ', array_filter([$l['website'] ?? '', $l['phone'] ?? '', $l['email'] ?? '']))) ?: 'No contact details found yet' ?>
          <?php foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram'] as $sk => $sl): if (!empty($l[$sk]) && preg_match('#^https?://#', $l[$sk])): ?> · <a href="<?= pm_h($l[$sk]) ?>" target="_blank" rel="noopener noreferrer"><?= $sl ?></a><?php endif; endforeach; ?></p>
        <?php if ($waOn && trim((string)($l['phone'] ?? '') . (string)($l['whatsapp'] ?? '')) !== ''): ?><p class="hint wa-optin"><?= pm_h(pm_wa_optin_line($l)) ?></p><?php endif; ?>
        <?php if (!empty($l['social_gaps'])): ?><p class="hint gaps"><b>Where they lack online:</b> <?= pm_h(implode(' · ', array_slice($l['social_gaps'], 0, 3))) ?></p><?php endif; ?>
        </section>
        <?php if (!empty($l['sitdown']['lines']) && !($sdReply && $convoTop)): $sdz = $l['sitdown']; ?>
        <section class="lc"><h4 class="lc-h">The team's sit-down <span class="muted">· <?= pm_h((string)$sdz['at']) ?></span></h4>
          <ul class="talklist"><?php foreach ($sdz['lines'] as [$sw, $ss]): ?><li><time></time><b><?= pm_h(pm_talk_name($sw)) ?>:</b><span><?= pm_h($ss) ?></span></li><?php endforeach; ?></ul>
          <p class="hint"><b>Decided: <?= pm_h(PM_SD_ACTIONS[$sdz['action']] ?? ($sdz['action'] === 'reply' ? 'Answer them' : $sdz['action'])) ?></b><?= $sdz['angle'] !== '' ? ' · ' . pm_h($sdz['angle']) : '' ?><?= $sdz['why'] !== '' ? ' · ' . pm_h($sdz['why']) : '' ?></p></section>
        <?php endif; ?>
        <?php if (!empty($l['research'])): $rz = $l['research']; ?>
        <section class="lc"><h4 class="lc-h">What the research found <span class="muted">· <?= pm_h(substr((string)$rz['at'], 0, 10)) ?></span></h4>
        <div class="research">
          <?php if (isset($rz['sure']) && !$rz['sure']): ?><p class="hint warnt">The researcher could not confirm it found this exact business online, so only facts our system checked itself are shown.</p><?php elseif (!empty($rz['identity'])): ?><p class="hint">Matched by: <?= pm_h($rz['identity']) ?></p><?php endif; ?>
          <?php foreach (array_slice((array)$rz['discoveries'], 0, 7) as $dz): ?><p>• <?= pm_h($dz['fact']) ?><?= ($dz['confidence'] ?? '') === 'checked' ? ' <span class="pill hot" title="Checked by our system just now">checked</span>' : (!empty($dz['date']) ? ' <span class="muted">(' . pm_h($dz['date']) . ')</span>' : '') ?><?= ($dz['confidence'] ?? '') === 'medium' ? ' <span class="muted">· directory or review site</span>' : '' ?> <a href="<?= pm_h($dz['source']) ?>" target="_blank" rel="noopener noreferrer">source</a></p><?php endforeach; ?>
          <?php foreach (array_slice((array)$rz['news'], 0, 2) as $nz): ?><p>• News: <?= pm_h($nz['headline']) ?> <a href="<?= pm_h($nz['source']) ?>" target="_blank" rel="noopener noreferrer">source</a></p><?php endforeach; ?>
          <?php if (!empty($rz['website']['verdict'])): ?><p>• Website: <?= pm_h($rz['website']['verdict']) ?></p><?php endif; ?>
          <?php if (!empty($rz['social']['verdict'])): ?><p>• Social: <?= pm_h($rz['social']['verdict']) ?></p><?php endif; ?>
          <?php if (!empty($rz['opportunities'])): ?><p class="opp"><b>How we can help</b> (for us, not to paste): <?= pm_h(implode(' · ', array_slice($rz['opportunities'], 0, 3))) ?></p><?php endif; ?>
          <?php if (!empty($rz['starters'])): ?><details class="sx-more"><summary><?= count($rz['starters']) ?> conversation starters, ready to send</summary>
            <?php foreach ($rz['starters'] as $sz): ?><div class="starter"><b><?= pm_h($sz['angle']) ?></b><?= $sz['subject'] !== '' ? ' · <span class="muted">' . pm_h($sz['subject']) . '</span>' : '' ?><p><?= nl2br(pm_h($sz['email'])) ?></p>
              <div class="btns"><button type="button" class="btn small primary" data-fill="email" data-subject="<?= pm_h($sz['subject']) ?>" data-text="<?= pm_h($sz['email']) ?>">Put in email</button>
              <?php if ($waOn && $sz['whatsapp'] !== ''): ?><button type="button" class="btn small" data-fill="whatsapp" data-text="<?= pm_h($sz['whatsapp']) ?>">Put in WhatsApp</button><?php endif; ?></div></div><?php endforeach; ?></details>
          <?php elseif (!empty($rz['hooks'])): ?><p class="opp"><b>Conversation hooks:</b></p><?php foreach (array_slice($rz['hooks'], 0, 3) as $hz): ?><p class="hook">“<?= pm_h($hz) ?>” <button type="button" class="btn small" data-copy="<?= pm_h($hz) ?>">Copy</button></p><?php endforeach; ?><?php endif; ?>
        </div></section>
        <?php endif; ?>
        <?php if (!empty($l['thread']) && !$convoTop): ?><section class="lc"><h4 class="lc-h">Conversation</h4><div class="thread">
          <?php foreach (array_slice($l['thread'], -4, null, true) as $ti => $tm): ?><div class="tm <?= $tm['dir'] === 'in' ? 'in' : 'out' ?>"><?= pm_adm_btn($csrf, 'msg', ['id' => $id, 'i' => $ti, 'back' => 'agents&lead=' . $id], 'Delete this message' . ($tm['dir'] === 'in' ? ' from them? If it is their only answer, the lead goes back to contacted.' : '?'), '×', 'icon admx') ?><b><?= $tm['dir'] === 'in' ? 'Them' : 'Us' ?></b> · <?= pm_h($tm['at']) ?><?= ($tm['ch'] ?? '') === 'wa' ? ' · WhatsApp' : '' ?><br><?= nl2br(pm_h(mb_substr($tm['text'], 0, 400))) ?></div><?php endforeach; ?></div></section><?php endif; ?>
        <section class="lc"><h4 class="lc-h">Notes</h4>
        <?php foreach (array_slice(array_reverse((array)($l['notes'] ?? []), true), 0, 3, true) as $ni => $n): ?><div class="hint note"><?= pm_adm_btn($csrf, 'note', ['id' => $id, 'i' => $ni, 'back' => 'agents&lead=' . $id], 'Delete this note?', '×', 'icon admx') ?><?= pm_h($n['at']) ?> · <?= pm_h($n['text']) ?><?= !empty($n['by']) ? ' · ' . pm_h($n['by']) : '' ?></div><?php endforeach; ?>
        <?= pm_social_panels('lead_card', $vb, ['lead' => $l]) ?>
        <form method="post" class="inline"><?= $post($id, 'note') ?><input type="text" name="text" placeholder="Add a note"><button class="btn small">Add</button></form>
        </section>
      </div>
      <div class="lc-col">
        <?= pm_view_lead_drafts($l, $csrf, $post, $hid) ?>
        <?php if ($convoTop): $inMsg = $l['thread'][$lastIn]; $earlier = array_slice((array)$l['thread'], 0, $lastIn, true); ?>
        <section class="lc rx-them"><h4 class="lc-h">They wrote <span class="muted">· <?= pm_h((string)$inMsg['at']) ?><?= ($inMsg['ch'] ?? '') === 'wa' ? ' · WhatsApp' : ' · email' ?><?= !empty($l['last_in_subject']) ? ' · “' . pm_h((string)$l['last_in_subject']) . '”' : '' ?></span></h4>
          <div class="rx-msg"><?= nl2br(pm_h(trim((string)preg_replace('/(\R[ \t\x{00a0}]*){3,}/u', "\n\n", (string)$inMsg['text'])))) ?></div>
          <?php if ($earlier): ?><details class="rx-earlier"><summary>Earlier in the conversation (<?= count($earlier) ?>)</summary><div class="thread">
            <?php foreach (array_slice($earlier, -6, null, true) as $ti => $tm): ?><div class="tm <?= $tm['dir'] === 'in' ? 'in' : 'out' ?>"><?= pm_adm_btn($csrf, 'msg', ['id' => $id, 'i' => $ti, 'back' => 'agents&lead=' . $id], 'Delete this message?', '×', 'icon admx') ?><b><?= $tm['dir'] === 'in' ? 'Them' : 'Us' ?></b> · <?= pm_h($tm['at']) ?><?= ($tm['ch'] ?? '') === 'wa' ? ' · WhatsApp' : '' ?><br><?= nl2br(pm_h(mb_substr($tm['text'], 0, 600))) ?></div><?php endforeach; ?></div></details><?php endif; ?>
        </section>
        <?php if ($sdReply): ?>
        <section class="lc rx-take"><h4 class="lc-h">The team's take <span class="muted">· <?= pm_h((string)$sdReply['at']) ?></span></h4>
          <?php if (!empty($sdReply['asked'])): ?><p class="rx-k">They asked</p><ul class="rx-list"><?php foreach ($sdReply['asked'] as $x): ?><li><?= pm_h($x) ?></li><?php endforeach; ?></ul><?php endif; ?>
          <?php if (!empty($sdReply['known'])): ?><p class="rx-k">What we can say for sure</p><ul class="rx-list"><?php foreach ($sdReply['known'] as $x): ?><li><?= pm_h($x) ?></li><?php endforeach; ?></ul><?php endif; ?>
          <?php if (!empty($sdReply['unknown'])): ?><p class="rx-k warnt">Not in our facts: check before sending</p><ul class="rx-list"><?php foreach ($sdReply['unknown'] as $x): ?><li><?= pm_h($x) ?></li><?php endforeach; ?></ul><?php endif; ?>
          <?php if (!empty($sdReply['options'])): ?><p class="rx-k">Options</p><div class="rx-opts"><?php foreach ($sdReply['options'] as $o): ?><div class="rx-opt"><b><?= pm_h($o['option']) ?></b><?php if ($o['for'] !== ''): ?><span class="rx-for">For: <?= pm_h($o['for']) ?></span><?php endif; ?><?php if ($o['against'] !== ''): ?><span class="rx-against">Against: <?= pm_h($o['against']) ?></span><?php endif; ?></div><?php endforeach; ?></div><?php endif; ?>
          <?php if ((string)$sdReply['angle'] !== ''): ?><p class="rx-decision"><b>Decided:</b> <?= pm_h((string)$sdReply['angle']) ?></p><?php endif; ?>
          <?php if (!empty($sdReply['to_confirm'])): ?><p class="hint warnt"><b>You confirm:</b> <?= pm_h((string)$sdReply['to_confirm']) ?></p><?php endif; ?>
          <?php if (!empty($sdReply['lines'])): ?><details class="rx-talk"><summary>How the team talked it over</summary><ul class="talklist"><?php foreach ($sdReply['lines'] as [$sw, $ss]): ?><li><b><?= pm_h(pm_talk_name($sw)) ?>:</b><span><?= pm_h($ss) ?></span></li><?php endforeach; ?></ul></details><?php endif; ?>
        </section>
        <?php elseif (pm_agents_ready()): ?>
        <form method="post" class="lc rx-take rx-none"><?= $post($id, 'sitdown') ?><p class="hint my-0">The team has not talked this message over yet.</p><button class="btn small" title="The team works out what they asked, what we know, the options, and writes the answer again (one AI call)">Team sit-down on their message</button></form>
        <?php endif; ?>
        <?php endif; ?>
        <?php if ($hasReply): $rp = $l['reply_draft']; $rpMail = (bool)filter_var($l['email'] ?? '', FILTER_VALIDATE_EMAIL); $rpWa = pm_wa_link($l, ''); $rpWaText = (string)($rp['whatsapp'] ?? ''); $rpTip = pm_reply_length_tip((string)($rp['body'] ?? '')); ?>
        <form method="post" class="replybox"><?= $post($id, 'save_reply') ?>
          <div class="rx-head"><b>Our answer</b><?php if (!empty($rp['needs_human'])): ?><span class="pill warn" title="Something in it needs your judgement, so it is never sent on its own">read before sending</span><?php endif; ?></div>
          <?php if (!empty($rp['next_step'])): ?><p class="hint my-0">Next: <?= pm_h($rp['next_step']) ?></p><?php endif; ?>
          <?php if ($rpMail): ?>
          <label class="rx-lab">Subject</label>
          <input type="text" name="subject" value="<?= pm_h($rp['subject'] ?? '') ?>" aria-label="Subject">
          <label class="rx-lab">Email <?php if ($rpTip !== ''): ?><span class="muted">· <?= pm_h($rpTip) ?></span><?php endif; ?></label>
          <textarea name="body" rows="<?= max(8, min(22, (int)ceil(mb_strlen((string)($rp['body'] ?? '')) / 70) + substr_count((string)($rp['body'] ?? ''), "\n"))) ?>" aria-label="Reply by email"><?= pm_h($rp['body'] ?? '') ?></textarea>
          <div class="sigprev" title="Added under the answer when it is sent"><span>Added when sent: your usual signature · <a href="?tab=targeting#defaultmsg">change it</a></span><pre><?= pm_h(pm_reply_signature()) ?></pre></div>
          <?= pm_share_reply_controls((string)($l['brand'] ?? pm_brand()), (array)($rp['attach'] ?? [])) ?>
          <?php else: ?><span class="hint">No email address for this lead: answer on WhatsApp<?= $rpWa === '' ? ' (no mobile number either; add one in the box on the right)' : '' ?>.</span><?php endif; ?>
          <?php if ($waOn && ($rpWa !== '' || !$rpMail)): ?><label class="hint">WhatsApp answer <?= $rpWaText === '' && trim((string)($rp['body'] ?? '')) === '' ? '(write it, or press Draft my answer under More → Paste their reply)' : '' ?></label>
          <textarea name="whatsapp" rows="2" aria-label="Reply on WhatsApp"><?= pm_h($rpWaText) ?></textarea><?= pm_share_reply_controls((string)($l['brand'] ?? pm_brand()), [], 'wa') ?><?php endif; ?>
          <div class="btns"><button class="btn small">Save</button><?= pm_regen_lead_buttons($l, 'reply_draft') ?>
            <?php if ($rpMail && trim((string)($rp['body'] ?? '')) !== ''): ?><button class="btn small primary" formaction="?tab=agents" name="do" value="send_reply" onclick="return confirm('Send this reply now?')">Send reply</button><?php endif; ?>
            <?php if ($waBiz && trim($rpWaText) !== '' && pm_wa_biz_window_open($l)): ?><button class="btn small primary" formaction="?tab=agents" name="do" value="send_reply_wa" onclick="return confirm('Send this answer on WhatsApp now?')">Send on WhatsApp Business</button><?php endif; ?>
            <?php if ($waOn && $rpWa !== ''): ?><a class="btn small" data-wa-log data-id="<?= pm_h($id) ?>" data-csrf="<?= pm_h($csrf) ?>" data-url="<?= pm_h($rpWa) ?>" href="<?= pm_h($rpWa) ?>" target="_blank" rel="noopener noreferrer">Reply on WhatsApp</a><?php endif; ?></div>
        </form>
        <?php endif; ?>
        <?= pm_pg_panel($l, fn($do) => $post($id, $do), $waOn) // their progress after they said yes: steps, where it stands, the update to send ?>
        <?php if ($dr || $l['status'] !== 'new' || !empty($l['research']['starters'])): ?>
        <?php if ($sentFirst): ?><details class="lc rx-first"><summary class="lc-h">The first message we sent <span class="muted">· <?= pm_h(substr((string)((array)$l['sent'])[0], 0, 10)) ?></span></summary><?php endif; ?>
        <form method="post" class="msg msgpanel"><?= $post($id, 'save_draft', $hid('which', $which) . $hid('fmt', '')) ?>
          <div class="mp-head"><h4 class="lc-h"><?= $which === 'followup' ? 'Follow-up ' . pm_followup_nth($l) . ($fuReady ? ' <span class="muted t-sm">· prepared by the Follow-up head ' . pm_h(substr((string)($fuReady['at'] ?? ''), 0, 10)) . '</span>' : '') : 'The first message' ?></h4></div>
          <details class="mp-to"><summary>To: <?= pm_h(implode(' · ', array_filter([(string)($l['contact'] ?? ''), (string)($l['email'] ?? '') ?: 'no email', (string)($l['phone'] ?? '')]))) ?></summary>
            <div class="two"><input type="text" name="email" value="<?= pm_h($l['email'] ?? '') ?>" placeholder="Email"><input type="text" name="contact" value="<?= pm_h($l['contact'] ?? '') ?>" placeholder="Contact name"></div>
            <input type="text" name="phone" value="<?= pm_h($l['phone'] ?? '') ?>" placeholder="<?= $waOn ? 'Phone / WhatsApp' : 'Phone' ?>"></details>
          <?= pm_compose_controls($id, $csrf, true, '', $waOn) ?>
          <label>Email</label>
          <input type="text" name="email_subject" value="<?= pm_h($dr['email_subject'] ?? '') ?>" placeholder="Subject">
          <textarea name="email_body" rows="6" placeholder="Message"><?= pm_h($dr['email_body'] ?? '') ?></textarea>
          <div class="sigprev" title="Added under every email when it is sent"><span>Added when sent · <a href="?tab=targeting#defaultmsg">change the signature</a></span><pre><?= pm_h(trim(pm_outreach_footer(pm_settings()))) ?></pre></div>
          <?= $which === 'first' ? pm_share_added_hint('email', (string)($l['brand'] ?? pm_brand())) : '' ?>
          <?php if ($waOn): ?><label>WhatsApp</label>
          <textarea name="whatsapp" rows="2"><?= pm_h($dr['whatsapp'] ?? '') ?></textarea><?php endif; ?>
          <?php if ($waOn && trim((string)($dr['whatsapp'] ?? '')) !== ''): ?><details class="aibox"><summary>The WhatsApp as it goes out (greeting and sign-off added)</summary><pre class="t-sm"><?= pm_h(pm_wa_text($l, (string)$dr['whatsapp'], $which === 'first')) ?></pre></details><?php endif; ?>
          <div class="btns"><button class="btn small">Save edits</button><?= pm_regen_lead_buttons($l, $which === 'followup' ? 'followup_draft' : 'drafts') ?></div>
          <?php
            $tsS = pm_settings();
            $testTo = (string)($_SESSION['test_to'] ?? '') ?: ((string)($tsS['email'] ?? '') ?: (string)($tsS['smtp']['from_email'] ?? ''));
            [$myWa, $myWaKind] = pm_wa_number((string)($tsS['phone'] ?? '')); $myWa = $myWaKind === 'landline' ? '' : $myWa;
            $waSaved = (string)($dr['whatsapp'] ?? '');
          ?>
          <div class="testbar"><b>Test before it goes</b>
            <input type="email" name="test_to" value="<?= pm_h($testTo) ?>" placeholder="Your email address" aria-label="Send the test email to">
            <button class="btn small" name="do" value="send_test" title="Sends this exact email (signature, links and attachments included) to the address on the left. Your edits are saved first. Nothing is recorded on the lead.">Send me a test email</button>
            <?php if (!$waOn): ?>
            <?php elseif (trim($waSaved) !== '' && $myWa !== ''): ?><a class="btn small" href="https://wa.me/<?= pm_h($myWa) ?>?text=<?= rawurlencode(pm_wa_text($l, $waSaved, $which === 'first')) ?>" target="_blank" rel="noopener noreferrer" data-wa-saved="<?= pm_h($waSaved) ?>" title="Opens WhatsApp to your own number with the message exactly as the lead will get it">Test the WhatsApp on my phone</a>
            <?php elseif (trim($waSaved) !== ''): ?><span class="hint">Add a mobile number under Business details to test the WhatsApp on your phone.</span><?php endif; ?>
          </div>
        </form>
        <?php if ($sentFirst): ?></details><?php endif; ?>
        <?php else: ?><p class="hint">No message yet.</p><?php endif; ?>
      </div>
    </div>

    <div class="actions">
      <div class="agrp main" aria-label="Reach them">
      <?php if (!empty($l['listen'])): ?><a class="btn primary" href="<?= pm_h((string)$l['listen']['url']) ?>" target="_blank" rel="noopener noreferrer">Open their post</a><button type="button" class="btn" data-copy="<?= pm_h((string)$l['listen']['reply']) ?>">Copy the reply</button>
        <?php if ($l['status'] === 'new'): ?><form method="post"><?= $post($id, 'status', $hid('status', 'contacted')) ?><button class="btn" title="You answered under their post: it moves to Contacted">I replied</button></form><?php endif; ?><?php endif; ?>
      <?php if ($canMail && !$sentFirst): ?><form method="post" onsubmit="return confirm('Send this email to <?= pm_h(addslashes($l['email'])) ?> now?')"><?= $post($id, 'send', $hid('which', $which)) ?><button class="btn primary"><?= $which === 'followup' ? 'Send follow-up' : 'Send email' ?></button></form><?php endif; ?>
      <?php if ($wa): $waFull = pm_wa_text($l, $dr['whatsapp'], $which === 'first'); ?><a class="btn" data-wa-log data-first data-id="<?= pm_h($id) ?>" data-csrf="<?= pm_h($csrf) ?>" data-text="<?= pm_h($waFull) ?>" data-url="<?= pm_h(pm_wa_link($l, '')) ?>" href="<?= pm_h(pm_wa_link($l, $waFull)) ?>" target="_blank" rel="noopener noreferrer" title="Checked and logged like the WhatsApp page: daily limit, STOP and the wording rules">WhatsApp</a><?php endif; ?>
      <?php if ($tel = pm_tel_link($l)): ?><a class="btn" href="<?= pm_h($tel) ?>">Call</a><?php endif; ?>
      <?php if (($l['status'] ?? '') === 'contacted' && !pm_lead_email_dead($l) && pm_agents_ready()): ?><form method="post"<?= empty($l['followup_draft']) ? '' : ' data-confirm="Write a new follow-up in place of the one shown?"' ?>><?= $post($id, 'followup_ai') ?><button class="btn" title="The Follow-up agent writes a follow-up for you to read; nothing is sent"><?= empty($l['followup_draft']) ? 'Draft follow-up with AI' : 'Draft a new follow-up' ?></button></form><?php endif; ?>
      <?php if (($l['status'] ?? '') === 'contacted' && !pm_lead_email_dead($l)): ?><form method="post"<?= empty($l['followup_draft']) ? '' : ' data-confirm="Put the default follow-up in place of the one shown?"' ?>><?= $post($id, 'followup_default') ?><button class="btn" title="The default follow-up of this business, no AI (Sales > Targeting and messages); nothing is sent">Use default follow-up</button></form><?php endif; ?>
      </div>
      <?php if (empty($l['listen'])): ?><div class="agrp" aria-label="Prepare the message">
      <?php if (!in_array($l['status'], ['won', 'lost', 'optout'], true)): ?><form method="post"><?= $post($id, 'sitdown') ?><button class="btn" title="The team talks this lead over and decides how best to approach it (one small AI call)">Team sit-down</button></form><?php endif; ?>
      <form method="post"><?= $post($id, 'research') ?><button class="btn" title="Website, Facebook, Instagram, reviews and recent news"><?= empty($l['research']) ? 'Research' : 'Research again' ?></button></form>
      <?php if (empty($dr['email_body'])): ?><form method="post"><?= $post($id, 'draft_outreach') ?><button class="btn">Draft outreach</button></form><?php endif; ?>
      <?php if ($which === 'first' && in_array($l['status'], ['new', 'qualified', 'drafted'], true)): ?><form method="post"<?= !empty($dr['email_body']) ? ' data-confirm="Replace the draft with the default message?"' : '' ?>><?= $post($id, 'use_default') ?><button class="btn" title="The default email and WhatsApp message of this business, no AI (Sales > Targeting and messages), opening with the detail the study found about them">Use default message</button><?php if (pm_lead_personal($l) !== ''): ?><button class="btn" name="plain" value="1" title="The default message with its usual opening, without the personal detail">Plain default</button><?php endif; ?></form><?php endif; ?>
      </div><?php endif; ?>
      <div class="agrp" aria-label="More for this lead">
      <?php if (pm_brand_is_custom((string)($l['brand'] ?? ''))): $op = pm_onepager_status((string)$l['brand']); [$osub, $obody] = pm_onepager_default_mail($l); ?>
      <details class="more drop"><summary class="btn">One-page offer</summary>
        <div class="menu" style="min-width:280px">
          <a class="btn small" href="?onepager=<?= pm_h((string)$l['brand']) ?>">Download the PDF</a>
          <?php if ($op['problems']): ?><p class="hint warnt"><?= pm_h(implode(' ', $op['problems'])) ?></p>
          <?php elseif (!pm_onepager_wrote($l) || !filter_var($l['email'] ?? '', FILTER_VALIDATE_EMAIL)): ?><p class="hint">It can be emailed once they have written to you and you have their email address.</p>
          <?php else: ?><form method="post"><?= $post($id, 'onepager_send') ?><input type="text" name="subject" value="<?= pm_h($osub) ?>" aria-label="Subject"><textarea name="body" rows="5" aria-label="Message"><?= pm_h($obody) ?></textarea>
            <button class="btn small primary" onclick="return confirm('Email the one-page offer to <?= pm_h(addslashes($l['email'])) ?> now?')">Send with the PDF</button></form><?php endif; ?>
        </div></details>
      <?php else: ?>
      <form method="post"><?= $post($id, 'proposal') ?><button class="btn">Create proposal</button></form>
      <?php endif; ?>
      <?php if ($focusId === ''): ?><a class="btn" href="?tab=agents&lead=<?= pm_h($id) ?>" title="Work on this business alone">Focus</a><?php endif; ?>
      <details class="more drop"><summary class="btn">More</summary>
        <div class="menu">
          <?php if ($wa): ?><form method="post"><?= $post($id, 'whatsapp_sent') ?><button class="btn small" title="For a message you sent from your phone without the button above">I sent a WhatsApp from my phone</button></form><?php endif; ?>
          <?php if (!empty($dr['email_body'])): ?><button type="button" class="btn small" data-copy="<?= pm_h(($dr['email_subject'] ?? '') . "\n\n" . $dr['email_body']) ?>">Copy the email text</button><?php endif; ?>
          <?php if ($waOn && !empty($dr['whatsapp'])): ?><button type="button" class="btn small" data-copy="<?= pm_h($dr['whatsapp']) ?>">Copy the WhatsApp text</button><?php endif; ?>
          <?php if ($team): ?><form method="post" class="assign"><?= $post($id, 'assign') ?><select name="owner" onchange="this.form.requestSubmit()" aria-label="Assign to"><option value="">Assign to…</option><?php foreach ($team as $m): ?><option <?= ($l['owner'] ?? '') === $m ? 'selected' : '' ?>><?= pm_h($m) ?></option><?php endforeach; ?></select></form><?php endif; ?>
          <?php foreach ((empty($l['sent']) || !empty($l['sent_by_hand']) ? ['contacted' => 'I already contacted them', 'drafted' => 'Back to Draft ready'] : []) + ['replied' => 'They replied', 'proposal' => 'Proposal sent', 'won' => 'Won', 'optout' => 'Do not contact'] as $k => $lab): if ($l['status'] === $k) continue; ?>
            <form method="post"><?= $post($id, 'status', $hid('status', $k)) ?><button class="btn small"><?= $lab ?></button></form>
          <?php endforeach; ?>
          <?php if ($l['status'] !== 'lost'): ?><form method="post" class="lostf"><?= $post($id, 'status', $hid('status', 'lost')) ?>
            <select name="lost_reason" aria-label="Why we lost it"><?php foreach (pm_lost_reasons() as $lk => $ll): ?><option value="<?= $lk ?>"><?= pm_h($ll) ?></option><?php endforeach; ?></select><button class="btn small">Lost</button></form><?php endif; ?>
          <?php if (!empty($l['approved_at'])): ?><form method="post"><?= $post($id, 'unapprove_send', $hid('ids[]', $id)) ?><button class="btn small">Take out of the send queue</button></form><?php endif; ?>
          <?php if (!$waOn): ?>
          <?php elseif (pm_wa_optin($l)): ?><form method="post"><?= $post($id, 'wa_optin_clear') ?><button class="btn small" title="They changed their mind about WhatsApp, but did not say STOP">Remove WhatsApp opt-in</button></form>
          <?php else: ?><form method="post"><?= $post($id, 'wa_optin') ?><button class="btn small" title="Press only when they told you it is fine to message them on WhatsApp">They agreed to WhatsApp messages</button></form><?php endif; ?>
          <details class="more"><summary class="btn small">Paste their reply</summary>
            <form method="post"><?= $post($id, 'paste_reply') ?><textarea name="text" rows="3" placeholder="Paste what they wrote (WhatsApp or another inbox)"></textarea>
              <select name="ch" aria-label="Where they wrote"><?php if ($waOn): ?><option value="wa">They wrote on WhatsApp</option><?php endif; ?><option value="email">They wrote by email</option></select><button class="btn small">Draft my answer</button></form></details>
          <?php if (pm_user_is_admin()): // deleting is the admin's ?><form method="post" onsubmit="return confirm('Remove this lead?')"><?= $post($id, 'delete') ?><button class="btn small danger">Remove lead</button></form>
          <form method="post" onsubmit="return confirm('Forget this lead everywhere (privacy)? Their data is removed; signed history is anonymised.')"><?= $post($id, 'lead_forget') ?><button class="btn small danger">Forget lead (privacy)</button></form><?php endif; ?>
        </div>
      </details>
      </div>
    </div>
  </div>
</details>
<?php endforeach; ?>
</div>
<?= $pager ?>

<!-- 6. More -->
<details class="card morecard"><summary>More: add a lead yourself, the archive</summary>
  <h3>Add a lead yourself</h3>
  <form method="post"><?= $post('', 'add') ?>
    <div class="row">
      <div><label>Business *</label><input type="text" name="name" required></div>
      <div><label>City *</label><input type="text" name="city" required></div>
      <div><label>Type of business</label><input type="text" name="type" list="segs" placeholder="e.g. lodge, school"><datalist id="segs"><?php foreach ($acfg['sectors'] as $sg): ?><option value="<?= pm_h($sg) ?>"><?php endforeach; ?></datalist></div>
      <div><label>Contact person</label><input type="text" name="contact"></div>
      <div><label>Email</label><input type="email" name="email"></div>
      <div><label>Phone</label><input type="text" name="phone"></div>
    </div>
    <div class="btns"><button class="btn small primary">Add lead</button></div>
  </form>
  <p class="hint">What the agents look for, their daily limits and how they answer are under <a href="?tab=targeting">Targeting and messages</a>.</p>
  <h3>Export</h3>
  <form method="post"><?= $post('', 'export') ?><button class="btn small" title="Download all leads of this business as JSON (no mail server details)">Download all leads</button></form>

  <?php $arch = array_filter(function_exists('pm_leads_archive') ? pm_leads_archive() : [], fn($x) => ($x['brand'] ?? 'promanaged') === pm_brand()); if ($arch):
      $aq = trim((string)($_GET['aq'] ?? '')); $ac = trim((string)($_GET['ac'] ?? '')); $at = trim((string)($_GET['at'] ?? ''));
      $found = pm_archive_search(pm_brand(), $aq, $ac, $at, 50); ?>
  <h3 id="archive">Archive <span class="muted">· <?= count($arch) ?> lead<?= count($arch) === 1 ? '' : 's' ?></span></h3>
  <p class="hint">Leads untouched for 12 months move here to keep your lists fast. Nothing is deleted: find them and restore any of them.</p>
  <form method="get" class="findrow" data-noload="1"><input type="hidden" name="tab" value="agents">
    <input type="text" name="aq" value="<?= pm_h($aq) ?>" placeholder="Name, contact or email" aria-label="Search the archive"><input type="text" name="ac" value="<?= pm_h($ac) ?>" placeholder="City" class="narrow" aria-label="City"><input type="text" name="at" value="<?= pm_h($at) ?>" placeholder="Type" class="narrow" aria-label="Type of business">
    <button class="btn small">Search</button><?php if ($aq . $ac . $at !== ''): ?><a class="btn small" href="?tab=agents#archive">Clear</a><?php endif; ?></form>
  <?php if (!$found): ?><p class="hint">No archived lead matches.</p><?php else: ?>
  <form method="post"><?= $post('', 'restore_leads') ?>
    <?php foreach ($found as $aid => $al): ?>
      <label class="task" style="cursor:pointer"><input type="checkbox" name="ids[]" value="<?= pm_h((string)$aid) ?>"><span><b><?= pm_h((string)$al['name']) ?></b> <span class="muted">· <?= pm_h((string)($al['city'] ?? '')) ?> · <?= pm_h((string)($al['type'] ?? '')) ?> · <?= pm_h(PM_LEAD_STATUSES[$al['status'] ?? ''] ?? (string)($al['status'] ?? '')) ?> · archived <?= pm_h(substr((string)($al['archived_at'] ?? ''), 0, 10)) ?></span></span></label>
    <?php endforeach; ?>
    <div class="btns"><button class="btn small primary">Restore the ticked leads</button><?php if (pm_user_is_admin()): ?><button class="btn small danger" name="action" value="admin_delete" data-confirm="Delete the ticked archived leads for good? This cannot be undone.">Delete the ticked for good</button><input type="hidden" name="what" value="archived"><input type="hidden" name="back" value="agents#archive"><?php endif; ?><span class="hint"><?= count($found) ?> shown<?= count($arch) > count($found) && $aq . $ac . $at === '' ? ' of ' . count($arch) . ': search to find the others' : '' ?></span></div>
  </form>
  <?php endif; ?>
  <?php endif; ?>
</details>
