<?php
/**
 * The Audit department (tab=audit, admin only; lib/sx_teamaudit.php). Three pages:
 *   (no section)  Activity log: who signed in, what each person did and what the app said back, the pages they opened and the agents' lines, with a summary per person
 *   followups     Follow-ups over any period: first messages, follow-ups, answers, replies and wins per day, week or month and per person, and the ones that are late now
 *   ai            AI auditor: the audit written by the AI from those numbers, the earlier audits, and the weekly switch
 * Needs $csrf, $section.
 */
$asec = in_array($section ?? '', ['followups', 'ai'], true) ? $section : '';
$ar = pm_ta_range($_GET, $asec === 'followups' ? '30d' : '7d');
$aBrand = in_array((string)($_GET['biz'] ?? ''), pm_brand_ids(), true) ? (string)$_GET['biz'] : '';
$aHeads = [
    '' => ['Activity log', 'Everything the team and the agents did: sign-ins, every action and what the app said back, pages opened. Only an admin sees this.'],
    'followups' => ['Follow-ups', 'How many leads were written to and followed up, by whom, and which follow-ups are late right now.'],
    'ai' => ['AI auditor', 'The AI reads the numbers of a period (never the messages) and writes the audit: what is going well, what is not, and what to do this week.'],
];
echo pm_ui_head($aHeads[$asec][0], $aHeads[$asec][1], $asec === '' ? '<a class="btn small" href="?tab=audit&amp;export=csv' . pm_h(pm_ta_range_qs($ar)) . '">Download CSV</a>' : '') . pm_ui_strip('audit', '', $asec);

$aBase = '?tab=audit' . ($asec !== '' ? '&section=' . $asec : '');
$aKeep = array_intersect_key($_GET, array_flip(['biz', 'who', 'kind', 'q', 'by']));
$aLink = fn(array $set) => $aBase . '&' . http_build_query(array_merge($aKeep, $set));

// The period: one pill per preset, "Pick dates" opens two date boxes
echo '<nav class="filters" aria-label="Period">';
foreach (PM_TA_PRESETS as $pk => $pl) {
    if ($pk !== 'custom') {
        echo '<a href="' . pm_h($aLink(['range' => $pk])) . '"' . ($ar['preset'] === $pk ? ' class="on" aria-current="true"' : '') . '>' . pm_h($pl) . '</a>';
    }
}
echo '</nav>';
?>
<form method="get" class="card">
  <input type="hidden" name="tab" value="audit"><?php if ($asec !== ''): ?><input type="hidden" name="section" value="<?= pm_h($asec) ?>"><?php endif; ?>
  <input type="hidden" name="range" value="custom">
  <div class="row">
    <div><label>From</label><input type="date" name="from" value="<?= pm_h($ar['from']) ?>" max="<?= date('Y-m-d') ?>"></div>
    <div><label>To</label><input type="date" name="to" value="<?= pm_h($ar['to']) ?>" max="<?= date('Y-m-d') ?>"></div>
    <?php if ($asec !== 'ai'): // the AI audit always covers every business ?>
    <div><label>Business</label><select name="biz"><option value="">Every business</option><?php foreach (pm_brand_ids() as $bid): ?><option value="<?= pm_h($bid) ?>"<?= $aBrand === $bid ? ' selected' : '' ?>><?= pm_h(pm_brand_name($bid)) ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <?php if ($asec === ''): ?>
      <div><label>Show</label><select name="kind">
        <?php foreach (['team' => 'What people did', 'all' => 'Everything (also pages and agents)', 'signins' => 'Sign-ins and refusals', 'risk' => 'Deletes and account changes', 'failed' => 'Things that failed', 'agent' => 'Agents only', 'view' => 'Pages opened'] as $kk => $kl): ?>
          <option value="<?= $kk ?>"<?= ($_GET['kind'] ?? 'team') === $kk ? ' selected' : '' ?>><?= pm_h($kl) ?></option><?php endforeach; ?></select></div>
    <?php elseif ($asec === 'followups'): ?>
      <div><label>Group by</label><select name="by"><?php foreach (['' => 'Best fit', 'day' => 'Day', 'week' => 'Week', 'month' => 'Month'] as $bk => $bl): ?><option value="<?= $bk ?>"<?= ($_GET['by'] ?? '') === $bk ? ' selected' : '' ?>><?= $bl ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
  </div>
  <?php if ($asec === ''): ?>
  <div class="row mt-2">
    <div><label>Person</label><select name="who"><option value="">Everyone</option>
      <?php foreach (pm_users() as $un => $uu): ?><option value="<?= pm_h($un) ?>"<?= ($_GET['who'] ?? '') === $un ? ' selected' : '' ?>><?= pm_h($uu['name']) ?></option><?php endforeach; ?>
      <option value="system"<?= ($_GET['who'] ?? '') === 'system' ? ' selected' : '' ?>>System and agents</option></select></div>
    <div><label>Search</label><input type="search" name="q" value="<?= pm_h((string)($_GET['q'] ?? '')) ?>" placeholder="A lead, an action, a word in the result"></div>
  </div>
  <?php endif; ?>
  <div class="btns mt-2"><button class="btn small primary">Show</button><span class="muted t-sm">Showing <?= pm_h($ar['label']) ?> (<?= pm_h(date('j M Y', strtotime($ar['from']))) ?> to <?= pm_h(date('j M Y', strtotime($ar['to']))) ?>)</span></div>
</form>
<?php
$aStart = pm_ta_log_start();
if ($asec !== 'followups' && ($aStart === '' || $aStart > $ar['from'] . ' 00:00:00')): ?>
  <p class="hint">The activity log started <?= $aStart === '' ? 'just now' : pm_h(date('j M Y \a\t H:i', strtotime($aStart))) ?>: nothing before that was recorded. Follow-ups are read from the leads, so they go back to the start.</p>
<?php endif;

/* ---------------- Activity log ---------------- */
if ($asec === ''):
    $rows = pm_ta_read($ar['from'], $ar['to']);
    if ($aBrand !== '') {
        $rows = array_values(array_filter($rows, fn($x) => ($x['brand'] ?? '') === $aBrand || in_array($x['kind'] ?? '', ['signin', 'signin_fail', 'signout'], true)));
    }
    $people = pm_ta_people($rows);
    $kind = (string)($_GET['kind'] ?? 'team');
    $who = (string)($_GET['who'] ?? '');
    $q = mb_strtolower(trim((string)($_GET['q'] ?? '')));
    $list = array_values(array_filter($rows, function ($x) use ($kind, $who, $q) {
        $k = (string)($x['kind'] ?? '');
        $keep = match ($kind) {
            'all' => true,
            'signins' => in_array($k, ['signin', 'signin_fail', 'signout', 'denied'], true),
            'risk' => ($x['flag'] ?? '') === 'risk',
            'failed' => ($x['ok'] ?? true) === false || in_array($k, ['signin_fail', 'denied'], true),
            'agent' => $k === 'agent',
            'view' => $k === 'view',
            default => !in_array($k, ['view', 'agent'], true),
        };
        if ($keep && $who !== '') {
            $keep = ($x['user'] ?? '') === $who;
        }
        if ($keep && $q !== '') {
            $keep = str_contains(mb_strtolower(($x['name'] ?? '') . ' ' . ($x['what'] ?? '') . ' ' . ($x['detail'] ?? '')), $q);
        }
        return $keep;
    }));
    $limit = max(200, min(5000, (int)($_GET['n'] ?? 200)));
    $count = fn(callable $f) => count(array_filter($rows, $f));
    $fails = $count(fn($x) => ($x['kind'] ?? '') === 'signin_fail');
    $risky = $count(fn($x) => ($x['flag'] ?? '') === 'risk');
    $agentErr = $count(fn($x) => ($x['kind'] ?? '') === 'agent' && ($x['ok'] ?? true) === false);
    $tile = fn(string $l, string $v, string $tone = '') => '<div class="hq-kpi ' . $tone . '"><span>' . pm_h($l) . '</span><b>' . pm_h($v) . '</b></div>';
    ?>
    <div class="hq-kpis">
      <?= $tile('People active', (string)count(array_filter($people, fn($p) => $p['actions'] + $p['views'] + $p['signins'] > 0))) ?>
      <?= $tile('Sign-ins', (string)$count(fn($x) => ($x['kind'] ?? '') === 'signin')) ?>
      <?= $tile('Actions', (string)$count(fn($x) => ($x['kind'] ?? '') === 'action')) ?>
      <?= $tile('Failed sign-ins', (string)$fails, $fails ? 'warn' : '') ?>
      <?= $tile('Deletes and account changes', (string)$risky, $risky ? 'warn' : '') ?>
      <?= $tile('Agent errors', (string)$agentErr, $agentErr ? 'warn' : '') ?>
    </div>

    <div class="card"><h2>Each person</h2>
      <?php if (!$people): ?><p class="muted">Nobody did anything in this period.</p><?php else: ?>
      <table class="grid"><thead><tr><th>Person</th><th>Last seen</th><th class="num">Sign-ins</th><th class="num">Active</th><th class="num">Actions</th><th>Mostly</th><th class="num">Pages</th><th class="num">Failed</th><th class="num">Deletes, accounts</th><th class="num">Out of hours</th></tr></thead><tbody>
      <?php foreach ($people as $p): arsort($p['areas']); ?>
        <tr><td class="nowrap"><?= pm_auth_av(['username' => $p['user'] ?: $p['name'], 'name' => $p['name']]) ?> <a href="<?= pm_h($aLink(['who' => $p['user'], 'kind' => 'team'] + ['range' => $ar['preset'], 'from' => $ar['from'], 'to' => $ar['to']])) ?>"><?= pm_h($p['name']) ?></a></td>
          <td class="nowrap muted"><?= $p['last'] !== '' ? pm_h(date('j M H:i', strtotime($p['last']))) : '–' ?></td>
          <td class="num"><?= $p['signins'] ?><?= $p['fails'] ? ' <span class="chip warn">' . $p['fails'] . ' failed</span>' : '' ?></td>
          <td class="num nowrap"><?= pm_h(pm_ta_dur($p['minutes'])) ?></td>
          <td class="num"><?= $p['actions'] ?></td>
          <td class="t-sm"><?= pm_h(implode(', ', array_map(fn($a, $n) => "$a $n", array_keys(array_slice($p['areas'], 0, 3, true)), array_slice($p['areas'], 0, 3, true))) ?: '–') ?></td>
          <td class="num"><?= $p['views'] ?></td>
          <td class="num"><?= $p['failed_actions'] ?: '–' ?></td>
          <td class="num"><?= $p['risky'] ? '<span class="chip warn">' . $p['risky'] . '</span>' : '–' ?></td>
          <td class="num"><?= $p['late'] ?: '–' ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
      <p class="hint">Active time adds up the gaps between a person's clicks that are shorter than 20 minutes. Out of hours: before 07:00, after 20:00 or at the weekend.</p>
      <?php endif; ?>
    </div>

    <div class="card"><h2>The log <span class="muted t-sm">· <?= count($list) ?> line<?= count($list) === 1 ? '' : 's' ?></span></h2>
      <?php if (!$list): ?><p class="muted">Nothing matches.</p><?php else: ?>
      <table class="grid"><thead><tr><th>When</th><th>Who</th><th>What</th><th>Result</th><th>Business</th></tr></thead><tbody>
      <?php foreach (array_slice($list, 0, $limit) as $x): $k = (string)($x['kind'] ?? '');
          $bad = ($x['ok'] ?? true) === false || in_array($k, ['signin_fail', 'denied'], true);
          $chip = match (true) { $k === 'action' => ($x['flag'] ?? '') === 'risk' ? '<span class="chip warn">' . pm_h((string)($x['area'] ?? 'Action')) . '</span>' : '<span class="chip">' . pm_h((string)($x['area'] ?? 'Action')) . '</span>',
              $bad => '<span class="chip bad">' . pm_h(PM_TA_KINDS[$k] ?? $k) . '</span>', $k === 'signin' => '<span class="chip ok">Signed in</span>', default => '<span class="chip">' . pm_h(PM_TA_KINDS[$k] ?? $k) . '</span>' }; ?>
        <tr><td class="nowrap muted tnum"><?= pm_h(date('j M H:i:s', strtotime((string)$x['at']))) ?></td>
          <td class="nowrap"><?= pm_h((string)($x['name'] ?? '')) ?><?= ($x['user'] ?? '') !== '' && ($x['user'] ?? '') !== 'system' ? '<br><span class="muted t-xs">@' . pm_h((string)$x['user']) . (($x['ip'] ?? '') !== '' ? ' · ' . pm_h((string)$x['ip']) : '') . '</span>' : '' ?></td>
          <td><?= $chip ?> <?= !empty($x['lead']) ? '<a href="?tab=agents&amp;brand=' . pm_h((string)($x['brand'] ?? '')) . '&amp;lead=' . pm_h((string)$x['lead']) . '">' . pm_h((string)$x['what']) . '</a>' : pm_h((string)($x['what'] ?? '')) ?></td>
          <td class="t-sm<?= $bad ? ' bad' : '' ?>"><?= $bad && ($x['detail'] ?? '') === '' ? 'Failed' : pm_h((string)($x['detail'] ?? '')) ?></td>
          <td class="nowrap t-sm muted"><?= ($x['brand'] ?? '') !== '' && in_array($x['brand'], pm_brand_ids(), true) ? pm_h(pm_brand_name((string)$x['brand'])) : '' ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
      <?php if (count($list) > $limit): ?><div class="btns"><a class="btn small" href="<?= pm_h($aLink(['n' => $limit + 500, 'range' => $ar['preset'], 'from' => $ar['from'], 'to' => $ar['to']])) ?>">Show 500 more</a><span class="muted t-sm"><?= count($list) - $limit ?> older lines</span></div><?php endif; ?>
      <?php endif; ?>
    </div>
<?php

/* ---------------- Follow-ups ---------------- */
elseif ($asec === 'followups'):
    $aby = pm_ta_auto_by($ar, (string)($_GET['by'] ?? ''));
    $fu = pm_ta_followups($ar, $aBrand, $aby);
    $t = $fu['tot'];
    $late3 = count(array_filter($fu['overdue'], fn($o) => $o['late'] >= 3));
    $tile = fn(string $l, string $v, string $tone = '') => '<div class="hq-kpi ' . $tone . '"><span>' . pm_h($l) . '</span><b>' . pm_h($v) . '</b></div>';
    $max = max(1, ...array_map(fn($s) => $s['followups'] + $s['first'], array_values($fu['series']) ?: [['followups' => 0, 'first' => 0]]));
    ?>
    <div class="hq-kpis">
      <?= $tile('First messages', (string)$t['first']) ?>
      <?= $tile('Follow-ups sent', (string)$t['followups']) ?>
      <?= $tile('Leads followed up', (string)$t['leads']) ?>
      <?= $tile('On time', $t['ontime_pct'] === null ? '–' : $t['ontime_pct'] . '%', $t['ontime_pct'] !== null && $t['ontime_pct'] < 70 ? 'warn' : '') ?>
      <?= $tile('Replies', (string)$t['replies']) ?>
      <?= $tile('Late right now', (string)count($fu['overdue']), $late3 ? 'warn' : '') ?>
    </div>

    <div class="card"><h2>Over time <span class="muted t-sm">· by <?= pm_h($aby) ?></span></h2>
      <table class="grid"><thead><tr><th><?= pm_h(ucfirst($aby)) ?></th><th class="num">First messages</th><th class="num">Follow-ups</th><th>Messages sent</th><th class="num">Leads followed up</th><th class="num">On time</th><th class="num">Replies</th><th class="num">Answers</th><th class="num">Proposals</th><th class="num">Won</th></tr></thead><tbody>
      <?php foreach (array_reverse($fu['series'], true) as $k => $s): $n = $s['first'] + $s['followups']; ?>
        <tr><td class="nowrap"><?= pm_h(pm_ta_bucket_label($k, $aby)) ?></td><td class="num"><?= $s['first'] ?: '–' ?></td><td class="num"><b><?= $s['followups'] ?: '–' ?></b></td>
          <td class="ta-bar" title="<?= pm_h($s['first'] . ' first messages, ' . $s['followups'] . ' follow-ups') ?>"><span class="sx-bar"><i style="width:<?= (int)round(100 * $n / $max) ?>%"></i></span></td>
          <td class="num"><?= $s['leads'] ?: '–' ?></td><td class="num"><?= $s['ontime_pct'] === null ? '–' : $s['ontime_pct'] . '%' ?></td><td class="num"><?= $s['replies'] ?: '–' ?></td><td class="num"><?= $s['answers'] ?: '–' ?></td>
          <td class="num"><?= $s['proposals'] ?: '–' ?></td><td class="num"><?= $s['won'] ?: '–' ?></td></tr>
      <?php endforeach; ?>
      </tbody><tfoot><tr><th>All of it</th><th class="num"><?= $t['first'] ?></th><th class="num"><?= $t['followups'] ?></th><th></th><th class="num"><?= $t['leads'] ?></th><th class="num"><?= $t['ontime_pct'] === null ? '–' : $t['ontime_pct'] . '%' ?></th><th class="num"><?= $t['replies'] ?></th><th class="num"><?= $t['answers'] ?></th><th class="num"><?= $t['proposals'] ?></th><th class="num"><?= $t['won'] ?></th></tr></tfoot></table>
      <p class="hint">A follow-up is every email or WhatsApp to a lead after the first one. On time: sent within the follow-up wait (Sales &gt; Targeting) plus two days. <?= $t['auto'] ? pm_h($t['auto'] . ' of the messages went out automatically (approved drafts sent by the drip).') : '' ?><?= $t['answer_h'] !== null ? ' Replies were answered in ' . pm_h((string)$t['answer_h']) . ' hours on average.' : '' ?></p>
    </div>

    <div class="card"><h2>Each person</h2>
      <?php if (!$fu['people']): ?><p class="muted">No messages in this period.</p><?php else: ?>
      <table class="grid"><thead><tr><th>Person</th><th class="num">First messages</th><th class="num">Follow-ups</th><th class="num">Leads followed up</th><th class="num">On time</th><th class="num">Answers</th><th class="num">Hours to answer</th><th class="num">Replies on their leads</th><th class="num">Won</th><th class="num">Due now</th><th class="num">Late 3+ days</th></tr></thead><tbody>
      <?php foreach ($fu['people'] as $p): ?>
        <tr><td class="nowrap"><?= pm_h($p['name'] === 'Automatic' ? 'Automatic (drip)' : ($p['name'] === 'Nobody' ? 'No owner' : $p['name'])) ?></td><td class="num"><?= $p['first'] ?: '–' ?></td><td class="num"><b><?= $p['followups'] ?: '–' ?></b></td><td class="num"><?= $p['leads'] ?: '–' ?></td>
          <td class="num"><?= $p['ontime_pct'] === null ? '–' : $p['ontime_pct'] . '%' ?></td><td class="num"><?= $p['answers'] ?: '–' ?></td><td class="num"><?= $p['answer_h'] ?? '–' ?></td><td class="num"><?= $p['replies'] ?: '–' ?></td><td class="num"><?= $p['won'] ?: '–' ?></td>
          <td class="num"><?= $p['owned_due'] ?: '–' ?></td><td class="num"><?= $p['owned_late'] ? '<span class="chip warn">' . $p['owned_late'] . '</span>' : '–' ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
      <p class="hint">What a person sent is counted under their name; replies, wins and late follow-ups are counted under the lead's owner.</p>
      <?php endif; ?>
    </div>

    <div class="card" id="late"><h2>Late right now <span class="muted t-sm">· <?= count($fu['overdue']) ?></span></h2>
      <?php if (!$fu['overdue']): ?><p class="muted">No follow-up is due. Every lead that went quiet has been followed up in time.</p><?php else: ?>
      <table class="grid"><thead><tr><th>Lead</th><th>Business</th><th>Owner</th><th class="num">Quiet for</th><th class="num">Late by</th><th class="num">Follow-ups so far</th><th>Draft</th></tr></thead><tbody>
      <?php foreach (array_slice($fu['overdue'], 0, 60) as $o): ?>
        <tr><td><a href="?tab=agents&amp;brand=<?= pm_h($o['brand']) ?>&amp;lead=<?= pm_h($o['lead']) ?>"><?= pm_h($o['name']) ?></a></td><td class="t-sm muted"><?= pm_h(pm_brand_name($o['brand'])) ?></td><td><?= pm_h($o['owner'] === 'Nobody' ? 'No owner' : $o['owner']) ?></td>
          <td class="num"><?= $o['silent'] ?> days</td><td class="num"><?= $o['late'] ? '<span class="chip ' . ($o['late'] >= 3 ? 'warn' : '') . '">' . $o['late'] . ' days</span>' : 'due today' ?></td><td class="num"><?= $o['n'] ?></td>
          <td><?= $o['drafted'] ? '<span class="chip ok">Drafted, not sent</span>' : '<span class="chip">Not drafted</span>' ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
      <?php endif; ?>
    </div>
<?php

/* ---------------- AI auditor ---------------- */
else:
    $reps = pm_ta_reports();
    $rid = (string)($_GET['report'] ?? '');
    $rep = $reps[0] ?? null;
    foreach ($reps as $x) {
        if ($x['id'] === $rid) {
            $rep = $x;
        }
    }
    $conf = pm_ta_conf();
    $sev = ['high' => 'bad', 'medium' => 'warn', 'low' => 'info'];
    $ver = ['good' => ['ok', 'Doing well'], 'watch' => ['info', 'Keep an eye'], 'concern' => ['warn', 'Concern'], 'inactive' => ['', 'Inactive']];
    ?>
    <div class="card"><div class="card-head"><h2>Run the audit</h2></div>
      <form method="post" class="btns mt-0"><input type="hidden" name="csrf" value="<?= pm_h($csrf) ?>"><input type="hidden" name="action" value="audit"><input type="hidden" name="do" value="run">
        <input type="hidden" name="range" value="<?= pm_h($ar['preset']) ?>"><input type="hidden" name="from" value="<?= pm_h($ar['from']) ?>"><input type="hidden" name="to" value="<?= pm_h($ar['to']) ?>">
        <button class="btn primary" data-busy="The AI auditor is reading the numbers…">Audit <?= pm_h(mb_strtolower($ar['label'])) ?> with AI</button>
        <span class="muted t-sm">One AI call. It reads counts only: activity, follow-ups, late leads, sign-ins, deletes and agent errors.</span></form>
      <form method="post" class="cluster mt-2"><input type="hidden" name="csrf" value="<?= pm_h($csrf) ?>"><input type="hidden" name="action" value="audit"><input type="hidden" name="do" value="weekly">
        <label class="tick"><input type="checkbox" name="on" value="1"<?= !empty($conf['weekly']) ? ' checked' : '' ?> onchange="this.form.submit()"> Audit the last 7 days by itself every Monday morning</label></form>
    </div>

    <?php if (!$rep): ?>
      <p class="muted">No audit yet. Choose a period above and press the button.</p>
    <?php else: ?>
      <div class="card">
        <div class="card-head"><h2>Audit of <?= pm_h(date('j M', strtotime($rep['from']))) ?> to <?= pm_h(date('j M Y', strtotime($rep['to']))) ?></h2>
          <span class="chip <?= $rep['score'] >= 75 ? 'ok' : ($rep['score'] >= 50 ? 'warn' : 'bad') ?>">Score <?= (int)$rep['score'] ?><?= $rep['grade'] !== '' ? ' · ' . pm_h($rep['grade']) : '' ?></span></div>
        <p class="hq-headline"><?= pm_h($rep['headline']) ?></p>
        <?php if ($rep['followups'] !== ''): ?><p><b>Follow-ups.</b> <?= pm_h($rep['followups']) ?></p><?php endif; ?>
        <p class="hint">Written <?= pm_h(date('j M Y H:i', strtotime($rep['at']))) ?> <?= $rep['by'] === 'weekly' ? 'by the weekly audit' : 'for ' . pm_h($rep['by']) ?>.</p>
      </div>

      <?php if ($rep['findings']): ?>
      <div class="card"><h2>Findings</h2>
        <table class="grid"><thead><tr><th>How serious</th><th>Area</th><th>Finding</th><th>Do this</th></tr></thead><tbody>
        <?php foreach ($rep['findings'] as $f): ?>
          <tr><td><span class="chip <?= $sev[$f['severity']] ?>"><?= pm_h(ucfirst($f['severity'])) ?></span></td><td class="nowrap t-sm"><?= pm_h($f['area']) ?></td>
            <td><?= pm_h($f['finding']) ?><?= $f['evidence'] !== '' ? '<br><span class="muted t-sm">' . pm_h($f['evidence']) . '</span>' : '' ?></td><td><?= pm_h($f['action']) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
      </div>
      <?php endif; ?>

      <?php if ($rep['people']): ?>
      <div class="card"><h2>Each person</h2>
        <table class="grid"><thead><tr><th>Person</th><th>Verdict</th><th>What the numbers say</th><th>One thing to do better</th></tr></thead><tbody>
        <?php foreach ($rep['people'] as $p): [$vc, $vl] = $ver[$p['verdict']]; ?>
          <tr><td class="nowrap"><?= pm_h($p['name']) ?></td><td><span class="chip <?= $vc ?>"><?= pm_h($vl) ?></span></td><td><?= pm_h($p['summary']) ?></td><td><?= pm_h($p['coaching']) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
      </div>
      <?php endif; ?>

      <?php if ($rep['next_checks']): ?>
      <div class="card"><h2>Look at next time</h2><ul><?php foreach ($rep['next_checks'] as $c): ?><li><?= pm_h($c) ?></li><?php endforeach; ?></ul></div>
      <?php endif; ?>

      <details class="card"><summary class="sum">The numbers the AI read</summary>
        <pre class="t-sm mt-2"><?= pm_h(json_encode($rep['facts'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details>
    <?php endif; ?>

    <?php if (count($reps) > 1): ?>
    <div class="card"><h2>Earlier audits</h2>
      <table class="grid"><thead><tr><th>Period</th><th>Written</th><th class="num">Score</th><th>Headline</th></tr></thead><tbody>
      <?php foreach ($reps as $x): ?>
        <tr<?= $rep && $x['id'] === $rep['id'] ? ' class="on"' : '' ?>><td class="nowrap"><a href="?tab=audit&amp;section=ai&amp;report=<?= pm_h($x['id']) ?>"><?= pm_h(date('j M', strtotime($x['from'])) . ' to ' . date('j M Y', strtotime($x['to']))) ?></a></td>
          <td class="nowrap muted t-sm"><?= pm_h(date('j M H:i', strtotime($x['at']))) ?><?= $x['by'] === 'weekly' ? ' · weekly' : '' ?></td><td class="num"><?= (int)$x['score'] ?></td><td class="t-sm"><?= pm_h($x['headline']) ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
    </div>
    <?php endif; ?>
<?php endif;
