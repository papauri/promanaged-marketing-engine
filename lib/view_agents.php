<?php
/**
 * The Agents screen. Included by index.php, so it shares its variables ($csrf, $tpl, $settings ...).
 * Layout, top to bottom: brand switch, find-a-business, run + progress, numbers, today's tasks, leads, then a "More" fold.
 */
$bsel = (string)($_GET['brand'] ?? ($_SESSION['abrand'] ?? 'promanaged'));
$_SESSION['abrand'] = pm_brand_norm($bsel);
pm_brand_set($_SESSION['abrand']);
$bname = pm_brand_title(pm_settings(), pm_brand());
$acfg = pm_agents_config();
$leadsAll = array_filter(pm_leads(), fn($l) => ($l['brand'] ?? 'promanaged') === pm_brand());
$run = pm_run_state();
$running = ($run['state'] ?? '') === 'running';
$plan = pm_daily_plan($leadsAll, $acfg);
$filter = (string)($_GET['status'] ?? 'active');
$ftype = (string)($_GET['type'] ?? '');
$fq = trim((string)($_GET['q'] ?? ''));
$focusId = isset($leadsAll[(string)($_GET['lead'] ?? '')]) ? (string)$_GET['lead'] : '';
$who = (string)($GLOBALS['PM_WHO'] ?? '');
$team = array_values(array_filter((array)($settings['team'] ?? [])));
$waiting = fn($l) => $l['status'] !== 'optout' && ($l['status'] === 'replied' || !empty($l['awaiting_reply_since']));
$inStatus = fn($l, $f) => $f === 'all' ? true : ($f === 'mine' ? (($l['owner'] ?? '') === $who && (!in_array($l['status'], ['won', 'lost', 'optout'], true) || $waiting($l))) : ($f === 'awaiting' ? $waiting($l)
    : ($f === 'active' ? (!in_array($l['status'], ['won', 'lost', 'optout'], true) || $waiting($l)) : $l['status'] === $f)));
$inSearch = fn($l) => $fq === '' || mb_stripos($l['name'] . ' ' . $l['city'] . ' ' . ($l['contact'] ?? '') . ' ' . ($l['type'] ?? ''), $fq) !== false;
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
$counts['all'] = count($base);
$counts['mine'] = $who === '' ? 0 : count(array_filter($base, fn($l) => $inStatus($l, 'mine')));
$shown = array_filter($base, fn($l) => $inStatus($l, $filter));
if ($focusId !== '') { $shown = [$focusId => $leadsAll[$focusId]]; }
usort($shown, fn($a, $b) => [$waiting($b), $b['score'] ?? 0] <=> [$waiting($a), $a['score'] ?? 0]);
$unmatched = pm_inbox_unmatched_list();
$hasApprove = function_exists('pm_approve_for_send');
$approvable = fn($l) => $hasApprove && in_array($l['status'], ['drafted', 'qualified'], true) && empty($l['approved_at']) && empty($l['sent']) && !empty($l['drafts']['email_body']) && filter_var($l['email'] ?? '', FILTER_VALIDATE_EMAIL);
$nApprovable = count(array_filter($shown, $approvable));
$sentToday = pm_sent_today($leadsAll);
$agentLog = pm_load('agent_log', fn() => []);
$hid = fn($n, $v) => '<input type="hidden" name="' . $n . '" value="' . pm_h((string)$v) . '">';
$post = fn($id, $do, $extra = '') => '<input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="agents"><input type="hidden" name="do" value="' . $do . '">'
    . $hid('rs', $filter) . $hid('rt', $ftype) . ($focusId !== '' ? $hid('rl', '1') : '') . ($id !== '' ? $hid('id', $id) : '') . $extra;
require_once __DIR__ . '/view_drafts.php';
$waBiz = pm_wa_biz_cfg()['ready'];
@set_time_limit(120);
$dir = pm_director_brief() + ['headline' => '', 'priorities' => [], 'focus' => [], 'drop' => [], 'experiment' => '']; // a new business has no advice yet
$ds = $dir['stats'];
$ndone = count(array_filter($plan, fn($t) => $t['done']));
?>
<?= pm_ui_head('Leads · ' . pm_h($bname), 'Find businesses, let the agents draft the messages, and send what you approve. Nothing goes out without you.') ?>
<?php if (!pm_agents_ready()): ?>
  <div class="flash err"><b>One thing to set up first.</b> Add <code><?= pm_h(pm_agents_missing_key()) ?>=...</code> to <code>.env</code>, then reload.</div>
<?php endif; ?>

<!-- 1. Find a business -->
<div class="card find">
  <form method="post" class="findrow"><?= $post('', 'lookup') ?><?= $hid('brand', pm_brand()) ?>
    <input type="text" name="biz" required data-suggest data-web="1" autocomplete="off" autocapitalize="words" spellcheck="false" placeholder="Find a business by name, <?= pm_brand() === 'travel' ? 'e.g. Mufasa Eco Lodge' : (pm_brand_is_custom(pm_brand()) ? '' : 'e.g. Sunbird Capital Hotel') ?>" aria-label="Business name">
    <input type="text" name="city" placeholder="City (optional)" aria-label="City" class="narrow">
    <button class="btn">Find</button>
    <button class="btn primary" name="proposal" value="1">Find + proposal</button>
  </form>
  <p class="hint" style="margin:6px 0 0">Start typing: it suggests businesses you already know, then ones a web search finds (those are marked, and only fill the box).</p>
  <form method="post" style="display:inline"><?= $post('', 'export') ?><button class="btn" title="Download all leads of this business as JSON (no mail server details)">Export</button></form>
</div>

<!-- 2. Run the agents + progress -->
<div class="card runbar" id="runbar" data-running="<?= $running ? '1' : '0' ?>">
  <div class="runtop">
    <div>
      <b><?= $running ? 'Agents are searching' : 'Find new leads' ?></b>
      <span class="muted" id="runsub">
        <?php if ($running): ?><?= pm_h($run['phase'] ?? 'Starting') ?><?php elseif (($run['state'] ?? '') === 'done' && ($run['mode'] ?? '') === 'names'): ?>Name search <?= pm_h(date('j M, H:i', strtotime((string)($run['finished'] ?? 'now')))) ?>: <?= (int)($run['stats']['named'] ?? 0) ?> leads have an owner or manager name<?php elseif (($run['state'] ?? '') === 'done'): $st = $run['stats'] ?? []; ?>Last run <?= pm_h(date('j M, H:i', strtotime((string)($run['finished'] ?? 'now')))) ?>: <?= (int)($st['added'] ?? 0) ?> new, <?= (int)($st['qualified'] ?? 0) ?> qualified, <?= (int)($st['drafted'] ?? 0) ?> drafts<?= !empty($st['errors']) ? ', ' . (int)$st['errors'] . ' errors' : '' ?><?php elseif (in_array($run['state'] ?? '', ['error', 'stalled'], true)): ?><?= $run['state'] === 'stalled' ? 'The last run stopped responding.' : 'The last run failed: ' . pm_h($run['msg'] ?? '') ?><?php else: ?>Scouts search the web, then qualify and draft. Nothing is sent without you.<?php endif; ?>
      </span>
    </div>
    <div class="acts">
      <?php if (pm_imap_ready()): ?><form method="post"><?= $post('', 'check_replies') ?><button class="btn" <?= $running ? 'disabled' : '' ?>>Check replies</button></form><?php endif; ?>
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

<!-- 3. Numbers + today's strategy -->
<div class="kpis">
  <div><b><?= (int)$ds['leads'] ?></b><span>leads<?= ($ds['hot'] ?? 0) ? ' · ' . (int)$ds['hot'] . ' hot' : '' ?></span></div>
  <div><b><?= (int)$ds['contacted'] ?> → <?= (int)$ds['replied'] ?></b><span>contacted → replied<?= $ds['contacted'] ? ' · ' . (int)$ds['reply_rate'] . '%' : '' ?></span></div>
  <div><b><?= (int)$ds['proposals_sent'] ?> → <?= (int)$ds['signed'] ?></b><span>proposals → signed</span></div>
  <div><b><?= $ds['signed_value'] ? pm_h(implode(' + ', array_map(fn($c, $v) => $c . ' ' . number_format($v), array_keys($ds['signed_value']), $ds['signed_value']))) : '—' ?></b><span>signed value</span></div>
</div>
<details class="card director" <?= $dir['priorities'] ? '' : '' ?>>
  <summary><span class="lbl">Today's strategy</span> <?= pm_h($dir['headline']) ?></summary>
  <?php if ($dir['priorities']): ?><ol><?php foreach ($dir['priorities'] as $pr): ?><li><?= pm_h($pr) ?></li><?php endforeach; ?></ol><?php endif; ?>
  <?php if ($dir['focus']): ?><p class="hint">Searching more of: <b><?= pm_h(implode(', ', $dir['focus'])) ?></b><?= $dir['drop'] ? ' · less of: ' . pm_h(implode(', ', $dir['drop'])) : '' ?></p><?php endif; ?>
  <?php if ($dir['experiment']): ?><p class="hint">Experiment this week: <?= pm_h($dir['experiment']) ?></p><?php endif; ?>
  <form method="post"><?= $post('', 'director') ?><button class="btn small">Refresh strategy</button></form>
</details>

<!-- 4. Today's tasks -->
<?php if ($plan): ?>
<div class="card"><h2>Today · <?= $ndone ?> of <?= count($plan) ?> done <span class="muted">· <?= $sentToday ?> of <?= (int)$acfg['send_cap'] ?> emails sent</span></h2>
  <?php foreach (array_slice($plan, 0, 6) as $t): ?>
    <div class="task <?= $t['done'] ? 'done' : '' ?>">
      <form method="post"><?= $post('', 'plan', $hid('task', $t['id']) . ($t['done'] ? '' : $hid('done', 1))) ?><button title="Mark done" aria-label="Mark done"><?= $t['done'] ? '✓' : '' ?></button></form>
      <span><?= pm_h($t['title']) ?></span>
      <?php if ($t['lead'] && isset($leadsAll[$t['lead']])): ?><a class="muted open" href="?tab=agents&status=all#l<?= pm_h($t['lead']) ?>">open</a><?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if (count($plan) > 6): ?><details class="more"><summary>Show the other <?= count($plan) - 6 ?></summary>
    <?php foreach (array_slice($plan, 6) as $t): ?><div class="task <?= $t['done'] ? 'done' : '' ?>">
      <form method="post"><?= $post('', 'plan', $hid('task', $t['id']) . ($t['done'] ? '' : $hid('done', 1))) ?><button title="Mark done" aria-label="Mark done"><?= $t['done'] ? '✓' : '' ?></button></form>
      <span><?= pm_h($t['title']) ?></span></div><?php endforeach; ?></details><?php endif; ?>
</div>
<?php endif; ?>

<!-- 5. Leads -->
<?php if ($focusId !== ''): ?>
<div class="focusbar"><a class="btn small" href="?tab=agents&status=<?= pm_h($filter) ?><?= $ftype !== '' ? '&type=' . urlencode($ftype) : '' ?><?= $fq !== '' ? '&q=' . urlencode($fq) : '' ?>#l<?= pm_h($focusId) ?>">← All leads</a> <span class="muted">Focused on <b><?= pm_h($leadsAll[$focusId]['name']) ?></b></span></div>
<?php else: ?>
<div class="filters">
  <?php foreach (($who !== '' ? ['mine' => 'Mine'] : []) + ['active' => 'Active', 'awaiting' => 'Needs reply', 'drafted' => 'Draft ready', 'contacted' => 'Contacted', 'replied' => 'Replied', 'proposal' => 'Proposal sent', 'won' => 'Won', 'all' => 'All'] as $k => $l): ?>
    <a href="?tab=agents&status=<?= $k ?><?= $ftype !== '' ? '&type=' . urlencode($ftype) : '' ?><?= $fq !== '' ? '&q=' . urlencode($fq) : '' ?>" class="<?= $filter === $k ? 'on' : '' ?>"><?= $l ?> <small><?= (int)($counts[$k] ?? 0) ?></small></a>
  <?php endforeach; ?>
  <form method="get" class="typesel" data-noload="1"><input type="hidden" name="tab" value="agents"><input type="hidden" name="status" value="<?= pm_h($filter) ?>">
    <input type="search" name="q" value="<?= pm_h($fq) ?>" placeholder="Search leads" aria-label="Search leads by name, town or contact" class="narrow">
    <select name="type" onchange="this.form.submit()" aria-label="Business type"><option value="">All business types</option>
      <?php foreach ($types as $tk => $tn): ?><option value="<?= pm_h($tk) ?>" <?= $ftype === $tk ? 'selected' : '' ?>><?= pm_h($tk) ?> (<?= $tn ?>)</option><?php endforeach; ?></select>
    <?php if ($ftype !== '' || $fq !== ''): ?><a class="btn small" href="?tab=agents&status=<?= pm_h($filter) ?>">Clear</a><?php endif; ?></form>
</div>
<?php endif; ?>
<?php if ($nApprovable): ?>
<form method="post" id="approveform" class="approvebar"><?= $post('', 'approve_send') ?><span class="muted"><?= $nApprovable ?> draft<?= $nApprovable === 1 ? '' : 's' ?> ready. Tick "Approve for sending" on the leads you have read, then</span> <button class="btn small primary">Approve selected</button>
  <span class="muted">They then go out slowly, one at a time, in working hours.</span></form>
<?php endif; ?>
<?php if ($hasApprove && function_exists('pm_view_send_queue') && count(array_filter($leadsAll, fn($x) => !empty($x['approved_at'])))) { pm_view_send_queue(pm_leads()); } ?>
<?php if (!$shown && ($ftype !== '' || $fq !== '')): ?><div class="card empty">No <?= pm_h($ftype ?: 'leads') ?><?= $fq !== '' ? ' matching "' . pm_h($fq) . '"' : '' ?> in this list. <a href="?tab=agents&status=all<?= $ftype !== '' ? '&type=' . urlencode($ftype) : '' ?><?= $fq !== '' ? '&q=' . urlencode($fq) : '' ?>">Look in All</a></div>
<?php elseif (!$shown): ?><div class="card empty">No leads here yet. <?= $filter === 'active' ? 'Press <b>Run the agents</b> or find a business by name.' : '' ?></div><?php endif; ?>
<?php foreach ($shown as $l):
    $id = $l['id'];
    $which = ($l['status'] === 'contacted' && !empty($l['followup_draft'])) ? 'followup' : 'first';
    $dr = $which === 'followup' ? $l['followup_draft'] : ($l['drafts'] ?? []);
    $canMail = !empty($dr['email_body']) && filter_var($l['email'] ?? '', FILTER_VALIDATE_EMAIL) && $l['status'] !== 'optout';
    $wa = !empty($dr['whatsapp']) ? pm_wa_link($l, $dr['whatsapp']) : '';
    $hasReply = !empty($l['reply_draft']);
?>
<details class="lead" id="l<?= pm_h($id) ?>" <?= ($which === 'followup' || $waiting($l) || $focusId === $id) ? 'open' : '' ?>>
  <summary>
    <span class="who"><b><?= pm_h($l['name']) ?></b><small><?= pm_h($l['type']) ?> · <?= pm_h($l['city']) ?></small></span>
    <span class="tags">
      <?php if ($hasReply): ?><span class="pill warn">reply waiting</span><?php elseif ($waiting($l)): ?><span class="pill warn">they wrote</span><?php elseif ($which === 'followup'): ?><span class="pill warn">follow-up ready</span><?php endif; ?>
      <?php foreach (pm_lead_open_drafts($l) as $od): ?><span class="pill warn"><?= ['winback' => 'win-back ready', 'postsign' => 'thank-you asks ready', 'reverify' => 'contact re-checked'][$od] ?></span><?php endforeach; ?>
      <?php if (!empty($l['snooze_until']) && $l['snooze_until'] >= date('Y-m-d')): ?><span class="pill" title="No follow-ups until then">paused to <?= pm_h(date('j M', strtotime($l['snooze_until']))) ?></span><?php endif; ?>
      <?php if (!empty($l['email_bad'])): ?><span class="pill warn" title="<?= pm_h((string)$l['email_bad']) ?> bounced: find another contact">email bounced</span><?php endif; ?>
      <?php if (!empty($l['view_count'])): ?><span class="pill hot" title="They opened the proposal page">opened proposal</span><?php endif; ?>
      <?php if (!empty($l['approved_at'])): ?><span class="pill hot"><?= pm_h(function_exists('pm_approved_eta') ? (pm_approved_eta($l) ?: 'approved') : 'approved') ?></span><?php endif; ?>
      <?php if ($approvable($l)): ?><label class="pick" onclick="event.stopPropagation()"><input type="checkbox" form="approveform" name="ids[]" value="<?= pm_h($id) ?>"> Approve for sending</label><?php endif; ?>
      <?php if ($l['status'] === 'lost' && !empty($l['lost_reason'])): ?><span class="pill"><?= pm_h(pm_lost_reasons()[$l['lost_reason']] ?? $l['lost_reason']) ?></span><?php endif; ?>
      <?php if (!empty($l['proposal_ai'])): ?><span class="pill hot">proposal ready</span><?php endif; ?>
      <?php if (!empty($l['owner'])): ?><span class="pill"><?= pm_h($l['owner']) ?></span><?php endif; ?>
      <span class="pill <?= ($l['score'] ?? 0) >= 70 ? 'hot' : '' ?>"><?= (int)($l['score'] ?? 0) ?></span>
      <span class="pill"><?= pm_h(PM_LEAD_STATUSES[$l['status']] ?? $l['status']) ?></span>
    </span>
  </summary>
  <div class="body">
    <div class="cols">
      <div>
        <p class="person"><?php if (!empty($l['contact'])): ?><b><?= pm_h($l['contact']) ?></b><?= !empty($l['contact_title']) ? ' · ' . pm_h($l['contact_title']) : '' ?><?php elseif (!empty($l['contact_hint'])): ?><span class="muted">Possible: <?= pm_h($l['contact_hint']) ?>. Not used yet: type the name in "Contact name" if it is right.</span><?php else: ?><span class="muted">No owner or manager name found yet. Add one below and the greeting uses it.</span><?php endif; ?></p>
        <?php if (!empty($l['pitch_response'])): $pr = $l['pitch_response']; ?><p class="hint warnt">On the proposal page they chose "<?= $pr['act'] === 'yes' ? 'Yes, send me the agreement' : 'I have a question' ?>"<?= $pr['msg'] !== '' ? ': ' . pm_h($pr['msg']) : '' ?>.</p><?php endif; ?>
        <?php if (!empty($l['view_count'])): ?><p class="hint">Opened the proposal <?= (int)$l['view_count'] ?> time<?= (int)$l['view_count'] === 1 ? '' : 's' ?>, last on <?= pm_h(date('j M, H:i', strtotime((string)($l['last_viewed_at'] ?? $l['viewed_at'])))) ?>.</p><?php endif; ?>
        <?php if (!empty($l['reason']) || !empty($l['pain'])): ?><p class="why"><?= pm_h($l['reason'] ?? '') ?><?php if (!empty($l['pain'])): ?> <b>Lead with:</b> <?= pm_h($l['pain']) ?><?php endif; ?></p><?php endif; ?>
        <?php foreach (array_slice((array)($l['evidence'] ?? []), 0, 3) as $e): ?><p class="hint ev">• <?= preg_replace('#(https?://[^\s<]+)#', '<a href="$1" target="_blank" rel="noopener noreferrer">source</a>', pm_h((string)$e)) ?></p><?php endforeach; ?>
        <p class="hint"><?= pm_h(implode(' · ', array_filter([$l['website'] ?? '', $l['phone'] ?? '', $l['email'] ?? '']))) ?: 'No contact details found yet' ?>
          <?php foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram'] as $sk => $sl): if (!empty($l[$sk]) && preg_match('#^https?://#', $l[$sk])): ?> · <a href="<?= pm_h($l[$sk]) ?>" target="_blank" rel="noopener noreferrer"><?= $sl ?></a><?php endif; endforeach; ?></p>
        <?php if (trim((string)($l['phone'] ?? '') . (string)($l['whatsapp'] ?? '')) !== ''): ?><p class="hint wa-optin"><?= pm_h(pm_wa_optin_line($l)) ?></p><?php endif; ?>
        <?php if (!empty($l['research'])): $rz = $l['research']; ?>
        <div class="research"><b>What we found</b> <span class="muted">· <?= pm_h($rz['at']) ?></span>
          <?php if (isset($rz['sure']) && !$rz['sure']): ?><p class="hint warnt">The researcher could not confirm it found this exact business online, so only facts our system checked itself are shown.</p><?php elseif (!empty($rz['identity'])): ?><p class="hint">Matched by: <?= pm_h($rz['identity']) ?></p><?php endif; ?>
          <?php foreach (array_slice((array)$rz['discoveries'], 0, 7) as $dz): ?><p>• <?= pm_h($dz['fact']) ?><?= ($dz['confidence'] ?? '') === 'checked' ? ' <span class="pill hot" title="Checked by our system just now">checked</span>' : (!empty($dz['date']) ? ' <span class="muted">(' . pm_h($dz['date']) . ')</span>' : '') ?><?= ($dz['confidence'] ?? '') === 'medium' ? ' <span class="muted">· directory or review site</span>' : '' ?> <a href="<?= pm_h($dz['source']) ?>" target="_blank" rel="noopener noreferrer">source</a></p><?php endforeach; ?>
          <?php foreach (array_slice((array)$rz['news'], 0, 2) as $nz): ?><p>• News: <?= pm_h($nz['headline']) ?> <a href="<?= pm_h($nz['source']) ?>" target="_blank" rel="noopener noreferrer">source</a></p><?php endforeach; ?>
          <?php if (!empty($rz['website']['verdict'])): ?><p>• Website: <?= pm_h($rz['website']['verdict']) ?></p><?php endif; ?>
          <?php if (!empty($rz['social']['verdict'])): ?><p>• Social: <?= pm_h($rz['social']['verdict']) ?></p><?php endif; ?>
          <?php if (!empty($rz['opportunities'])): ?><p class="opp"><b>How we can help</b> (for us, not to paste): <?= pm_h(implode(' · ', array_slice($rz['opportunities'], 0, 3))) ?></p><?php endif; ?>
          <?php if (!empty($rz['starters'])): ?><p class="opp"><b>Conversation starters</b> <span class="muted">· ready to send, built on what we found</span></p>
            <?php foreach ($rz['starters'] as $sz): ?><div class="starter"><b><?= pm_h($sz['angle']) ?></b><?= $sz['subject'] !== '' ? ' · <span class="muted">' . pm_h($sz['subject']) . '</span>' : '' ?><p><?= nl2br(pm_h($sz['email'])) ?></p>
              <div class="btns"><button type="button" class="btn small primary" data-fill="email" data-subject="<?= pm_h($sz['subject']) ?>" data-text="<?= pm_h($sz['email']) ?>">Put in email</button>
              <?php if ($sz['whatsapp'] !== ''): ?><button type="button" class="btn small" data-fill="whatsapp" data-text="<?= pm_h($sz['whatsapp']) ?>">Put in WhatsApp</button><?php endif; ?></div></div><?php endforeach; ?>
          <?php elseif (!empty($rz['hooks'])): ?><p class="opp"><b>Conversation hooks:</b></p><?php foreach (array_slice($rz['hooks'], 0, 3) as $hz): ?><p class="hook">“<?= pm_h($hz) ?>” <button type="button" class="btn small" data-copy="<?= pm_h($hz) ?>">Copy</button></p><?php endforeach; ?><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($l['social_gaps'])): ?><p class="hint gaps"><b>Where they lack online:</b> <?= pm_h(implode(' · ', array_slice($l['social_gaps'], 0, 3))) ?></p><?php endif; ?>
        <?php if (!empty($l['thread'])): ?><div class="thread"><b class="hint">Conversation</b>
          <?php foreach (array_slice($l['thread'], -4) as $tm): ?><p class="<?= $tm['dir'] === 'in' ? 'in' : 'out' ?>"><b><?= $tm['dir'] === 'in' ? 'Them' : 'Us' ?></b> · <?= pm_h($tm['at']) ?><?= ($tm['ch'] ?? '') === 'wa' ? ' · WhatsApp' : '' ?><br><?= nl2br(pm_h(mb_substr($tm['text'], 0, 400))) ?></p><?php endforeach; ?></div><?php endif; ?>
        <?php foreach (array_slice(array_reverse((array)($l['notes'] ?? [])), 0, 3) as $n): ?><p class="hint note"><?= pm_h($n['at']) ?> · <?= pm_h($n['text']) ?><?= !empty($n['by']) ? ' · ' . pm_h($n['by']) : '' ?></p><?php endforeach; ?>
        <?= pm_social_panels('lead_card', $vb, ['lead' => $l]) ?>
        <form method="post" class="inline"><?= $post($id, 'note') ?><input type="text" name="text" placeholder="Add a note"><button class="btn small">Add</button></form>
      </div>
      <div>
        <?= pm_view_lead_drafts($l, $csrf, $post, $hid) ?>
        <?php if ($hasReply): $rp = $l['reply_draft']; $rpMail = (bool)filter_var($l['email'] ?? '', FILTER_VALIDATE_EMAIL); $rpWa = pm_wa_link($l, ''); $rpWaText = (string)($rp['whatsapp'] ?? ''); ?>
        <form method="post" class="replybox"><?= $post($id, 'save_reply') ?>
          <b>Their reply is waiting</b><?php if (!empty($rp['next_step'])): ?><span class="hint"> Next: <?= pm_h($rp['next_step']) ?></span><?php endif; ?>
          <?php if (!empty($rp['needs_human'])): ?><span class="hint warnt">Needs your judgement: not safe to send automatically.</span><?php endif; ?>
          <?php if ($rpMail): ?>
          <input type="text" name="subject" value="<?= pm_h($rp['subject'] ?? '') ?>" aria-label="Subject">
          <textarea name="body" rows="5" aria-label="Reply by email"><?= pm_h($rp['body'] ?? '') ?></textarea>
          <?php else: ?><span class="hint">No email address for this lead: answer on WhatsApp<?= $rpWa === '' ? ' (no mobile number either; add one in the box on the right)' : '' ?>.</span><?php endif; ?>
          <?php if ($rpWa !== '' || !$rpMail): ?><label class="hint">WhatsApp answer <?= $rpWaText === '' && trim((string)($rp['body'] ?? '')) === '' ? '(write it, or press Draft my answer under More → Paste their reply)' : '' ?></label>
          <textarea name="whatsapp" rows="2" aria-label="Reply on WhatsApp"><?= pm_h($rpWaText) ?></textarea><?php endif; ?>
          <div class="btns"><button class="btn small">Save</button>
            <?php if ($rpMail && trim((string)($rp['body'] ?? '')) !== ''): ?><button class="btn small primary" formaction="?tab=agents" name="do" value="send_reply" onclick="return confirm('Send this reply now?')">Send reply</button><?php endif; ?>
            <?php if ($waBiz && trim($rpWaText) !== '' && pm_wa_biz_window_open($l)): ?><button class="btn small primary" formaction="?tab=agents" name="do" value="send_reply_wa" onclick="return confirm('Send this answer on WhatsApp now?')">Send on WhatsApp Business</button><?php endif; ?>
            <?php if ($rpWa !== ''): ?><a class="btn small" data-wa-log data-id="<?= pm_h($id) ?>" data-csrf="<?= pm_h($csrf) ?>" data-url="<?= pm_h($rpWa) ?>" href="<?= pm_h($rpWa) ?>" target="_blank" rel="noopener noreferrer">Reply on WhatsApp</a><?php endif; ?></div>
        </form>
        <?php endif; ?>
        <?php if ($dr || $l['status'] !== 'new' || !empty($l['research']['starters'])): ?>
        <form method="post" class="msg"><?= $post($id, 'save_draft', $hid('which', $which)) ?>
          <?= pm_compose_controls($id, $csrf) ?>
          <label>To</label>
          <div class="two"><input type="text" name="email" value="<?= pm_h($l['email'] ?? '') ?>" placeholder="Email"><input type="text" name="contact" value="<?= pm_h($l['contact'] ?? '') ?>" placeholder="Contact name"></div>
          <input type="text" name="phone" value="<?= pm_h($l['phone'] ?? '') ?>" placeholder="Phone / WhatsApp">
          <label>Email</label>
          <input type="text" name="email_subject" value="<?= pm_h($dr['email_subject'] ?? '') ?>" placeholder="Subject">
          <textarea name="email_body" rows="6" placeholder="Message (your sign-off and an opt-out line are added when sent)"><?= pm_h($dr['email_body'] ?? '') ?></textarea>
          <label>WhatsApp</label>
          <textarea name="whatsapp" rows="2"><?= pm_h($dr['whatsapp'] ?? '') ?></textarea>
          <div class="btns"><button class="btn small">Save edits</button></div>
        </form>
        <?php else: ?><p class="hint">No message yet.</p><?php endif; ?>
      </div>
    </div>

    <div class="actions">
      <?php if ($canMail): ?><form method="post" onsubmit="return confirm('Send this email to <?= pm_h(addslashes($l['email'])) ?> now?')"><?= $post($id, 'send', $hid('which', $which)) ?><button class="btn primary"><?= $which === 'followup' ? 'Send follow-up' : 'Send email' ?></button></form><?php endif; ?>
      <?php if ($wa): ?><a class="btn" href="<?= pm_h(pm_wa_link($l, pm_wa_text($l, $dr['whatsapp']))) ?>" target="_blank" rel="noopener noreferrer">WhatsApp</a><?php endif; ?>
      <?php if ($tel = pm_tel_link($l)): ?><a class="btn" href="<?= pm_h($tel) ?>">Call</a><?php endif; ?>
      <form method="post"><?= $post($id, 'research') ?><button class="btn" title="Website, Facebook, Instagram, reviews and recent news"><?= empty($l['research']) ? 'Research' : 'Research again' ?></button></form>
      <?php if (empty($dr['email_body'])): ?><form method="post"><?= $post($id, 'draft_outreach') ?><button class="btn">Draft outreach</button></form><?php endif; ?>
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
          <?php if ($wa): ?><form method="post"><?= $post($id, 'whatsapp_sent') ?><button class="btn small">I sent the WhatsApp</button></form><?php endif; ?>
          <?php if (!empty($dr['email_body'])): ?><button type="button" class="btn small" data-copy="<?= pm_h(($dr['email_subject'] ?? '') . "\n\n" . $dr['email_body']) ?>">Copy the email text</button><?php endif; ?>
          <?php if (!empty($dr['whatsapp'])): ?><button type="button" class="btn small" data-copy="<?= pm_h($dr['whatsapp']) ?>">Copy the WhatsApp text</button><?php endif; ?>
          <?php if ($team): ?><form method="post" class="assign"><?= $post($id, 'assign') ?><select name="owner" onchange="this.form.requestSubmit()" aria-label="Assign to"><option value="">Assign to…</option><?php foreach ($team as $m): ?><option <?= ($l['owner'] ?? '') === $m ? 'selected' : '' ?>><?= pm_h($m) ?></option><?php endforeach; ?></select></form><?php endif; ?>
          <?php foreach (['replied' => 'They replied', 'proposal' => 'Proposal sent', 'won' => 'Won', 'optout' => 'Do not contact'] as $k => $lab): if ($l['status'] === $k) continue; ?>
            <form method="post"><?= $post($id, 'status', $hid('status', $k)) ?><button class="btn small"><?= $lab ?></button></form>
          <?php endforeach; ?>
          <?php if ($l['status'] !== 'lost'): ?><form method="post" class="lostf"><?= $post($id, 'status', $hid('status', 'lost')) ?>
            <select name="lost_reason" aria-label="Why we lost it"><?php foreach (pm_lost_reasons() as $lk => $ll): ?><option value="<?= $lk ?>"><?= pm_h($ll) ?></option><?php endforeach; ?></select><button class="btn small">Lost</button></form><?php endif; ?>
          <?php if (!empty($l['approved_at'])): ?><form method="post"><?= $post($id, 'unapprove_send', $hid('ids[]', $id)) ?><button class="btn small">Take out of the send queue</button></form><?php endif; ?>
          <?php if (pm_wa_optin($l)): ?><form method="post"><?= $post($id, 'wa_optin_clear') ?><button class="btn small" title="They changed their mind about WhatsApp, but did not say STOP">Remove WhatsApp opt-in</button></form>
          <?php else: ?><form method="post"><?= $post($id, 'wa_optin') ?><button class="btn small" title="Press only when they told you it is fine to message them on WhatsApp">They agreed to WhatsApp messages</button></form><?php endif; ?>
          <details class="more"><summary class="btn small">Paste their reply</summary>
            <form method="post"><?= $post($id, 'paste_reply') ?><textarea name="text" rows="3" placeholder="Paste what they wrote (WhatsApp or another inbox)"></textarea>
              <select name="ch" aria-label="Where they wrote"><option value="wa">They wrote on WhatsApp</option><option value="email">They wrote by email</option></select><button class="btn small">Draft my answer</button></form></details>
          <form method="post" onsubmit="return confirm('Remove this lead?')"><?= $post($id, 'delete') ?><button class="btn small danger">Remove lead</button></form>
          <form method="post" onsubmit="return confirm('Forget this lead everywhere (privacy)? Their data is removed; signed history is anonymised.')"><?= $post($id, 'lead_forget') ?><button class="btn small danger">Forget lead (privacy)</button></form>
        </div>
      </details>
    </div>
  </div>
</details>
<?php endforeach; ?>

<!-- 6. More -->
<details class="card morecard"><summary>More: add a lead, settings, activity</summary>
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

  <h3>Agent settings</h3>
  <form method="post"><?= $post('', 'config') ?>
    <?php if (pm_brand() !== 'travel'): ?><label>What we sell (one line each, "Name: description")</label>
    <textarea name="cfg[offerings]" rows="4"><?= pm_h(implode("\n", $acfg['offerings'])) ?></textarea><?php endif; ?>
    <?php if (pm_brand_is_custom(pm_brand())): ?><label>Existing clients (one per line; they are never cold-pitched)</label>
    <textarea name="cfg[existing_clients]" rows="3"><?= pm_h(implode("\n", (array)($acfg['existing_clients'] ?? []))) ?></textarea><?php endif; ?>
    <div class="row">
      <div><label>Cities to search (comma separated)</label><input type="text" name="cfg[cities]" value="<?= pm_h(implode(', ', $acfg['cities'])) ?>"></div>
      <div><label>Types of business to look for (comma separated)</label><input type="text" name="cfg[sectors]" value="<?= pm_h(implode(', ', $acfg['sectors'])) ?>"></div>
    </div>
    <div class="row">
      <div><label>New leads per day</label><input type="number" name="cfg[new_per_day]" value="<?= (int)$acfg['new_per_day'] ?>"></div>
      <div><label>Searches per day</label><input type="number" name="cfg[scouts_per_day]" value="<?= (int)$acfg['scouts_per_day'] ?>"></div>
      <div><label>WhatsApp messages per day (limit)</label><input type="number" name="cfg[wa_cap]" value="<?= (int)$acfg['wa_cap'] ?>"></div>
      <div><label>Emails per day (limit)</label><input type="number" name="cfg[send_cap]" value="<?= (int)$acfg['send_cap'] ?>"></div>
      <div><label>Follow up after (days)</label><input type="number" name="cfg[followup_days]" value="<?= (int)$acfg['followup_days'] ?>"></div>
      <div><label>Follow-ups at most</label><input type="number" name="cfg[max_followups]" value="<?= (int)$acfg['max_followups'] ?>"></div>
      <div><label>Agents at once</label><input type="number" name="cfg[parallel]" value="<?= (int)$acfg['parallel'] ?>"></div>
      <div><label>Email replies</label><select name="cfg[reply_mode]"><option value="draft" <?= $acfg['reply_mode'] === 'draft' ? 'selected' : '' ?>>Draft for my approval</option><option value="auto" <?= $acfg['reply_mode'] === 'auto' ? 'selected' : '' ?>>Auto-send simple, safe replies</option></select></div>
      <div><label>WhatsApp Business answers<?= $waBiz ? '' : ' (off until WA_BIZ keys are in .env)' ?></label><select name="cfg[wa_reply_mode]"><option value="auto" <?= ($acfg['wa_reply_mode'] ?? 'auto') === 'auto' ? 'selected' : '' ?>>Answer simple questions by themselves</option><option value="draft" <?= ($acfg['wa_reply_mode'] ?? 'auto') === 'draft' ? 'selected' : '' ?>>Draft for my approval</option></select></div>
      <div><label>Daily AI budget (tokens; 0 = none)</label><input type="number" name="cfg[daily_token_budget]" value="<?= (int)$acfg['daily_token_budget'] ?>"></div>
      <div><label>Quality</label><select name="cfg[quality]"><option value="economy" <?= $acfg['quality'] === 'economy' ? 'selected' : '' ?>>Economy (cheapest)</option><option value="balanced" <?= $acfg['quality'] === 'balanced' ? 'selected' : '' ?>>Balanced</option></select></div>
      <div><label>Gemini model</label><select name="cfg[gemini_model]"><option value="economy" <?= $acfg['gemini_model'] === 'economy' ? 'selected' : '' ?>>Economy (recommended)</option><option value="auto" <?= $acfg['gemini_model'] === 'auto' ? 'selected' : '' ?>>Spread across the newest 3</option>
        <?php foreach (pm_gemini_top_models() as $gm): ?><option value="<?= pm_h($gm) ?>" <?= $acfg['gemini_model'] === $gm ? 'selected' : '' ?>><?= pm_h($gm) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="checks"><?php foreach ($acfg['enabled'] as $k => $on): ?><label class="check"><input type="checkbox" name="cfg[enabled][<?= $k ?>]" value="1" <?= $on ? 'checked' : '' ?>> <?= pm_h(ucfirst($k)) ?></label><?php endforeach; ?></div>
    <div class="btns"><button class="btn small primary">Save settings</button></div>
    <?php $us = pm_usage_today(); arsort($us['agents']); ?><p class="hint"><b>AI use today:</b> <?= number_format($us['total']) ?> tokens in <?= (int)$us['calls'] ?> calls<?= pm_budget() ? ' of a ' . number_format(pm_budget()) . ' budget' : '' ?><?= $us['agents'] ? ' (' . pm_h(implode(', ', array_map(fn($k, $v) => str_replace('_', ' ', $k) . ' ' . number_format($v), array_keys(array_slice($us['agents'], 0, 6, true)), array_slice($us['agents'], 0, 6, true)))) . ')' : '' ?>. Newest Gemini models today: <?= pm_h(implode(', ', pm_gemini_top_models())) ?>.</p>
  </form>
  <form method="post"><?= $post('', 'refresh_models') ?><button class="btn small">Refresh the Gemini model list</button></form>

  <h3>The agents</h3>
  <ul class="roster">
    <li><b>Scout</b> searches the web for businesses that need us.</li>
    <li><b>Contact Finder</b> finds an owner and a published contact.</li>
    <li><b>Qualifier</b> scores each lead and picks the package.</li>
    <li><b>Writer</b> drafts a short email and WhatsApp message.</li>
    <li><b>Proposal agent</b> tailors a proposal to one business.</li>
    <li><b>Follow-up</b> nudges quiet leads. <b>Reply agent</b> answers replies. <b>Director</b> sets the day's strategy.</li>
  </ul>
  <p class="hint">Searching with <?= pm_provider(true) === 'gemini' ? 'Gemini' : 'Claude' ?>, writing with <?= pm_provider(false) === 'gemini' ? 'Gemini' : 'Claude' ?>. To run every morning without opening the app, schedule <code>schedule_agents.bat</code>.</p>

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
    <div class="btns"><button class="btn small primary">Restore the ticked leads</button><span class="hint"><?= count($found) ?> shown<?= count($arch) > count($found) && $aq . $ac . $at === '' ? ' of ' . count($arch) . ': search to find the others' : '' ?></span></div>
  </form>
  <?php endif; ?>
  <?php endif; ?>

  <h3>Activity</h3>
  <?php if (!$agentLog): ?><p class="hint">Nothing yet.</p><?php endif; ?>
  <?php foreach (array_slice($agentLog, 0, 20) as $e): ?><p class="hint log"><?= pm_h(date('j M H:i', strtotime($e['at']))) ?> · <b><?= pm_h($e['agent']) ?></b> · <?= pm_h($e['msg']) ?></p><?php endforeach; ?>
</details>
