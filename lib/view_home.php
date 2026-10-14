<?php
/**
 * The agency overview (tab=home): the marketing firm on one screen. A greeting, today's brief from the Director, the numbers that matter, what needs the owner now, one card per department
 * (its state, in one row), every business at a glance, and the latest lines of the team's diary. Everything is read from stored data: no AI, no network, instant.
 * Needs $vb, $settings.
 */
$hd = pm_home_data($vb);
$cards = pm_home_depts($vb, $hd);
$bname = pm_brand_title($settings, $vb);
$reg = pm_ui_nav();
$hour = (int)date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$who = trim((string)($GLOBALS['PM_WHO'] ?? ''));
$brief = $hd['brief'];
$s = $hd['sales'];
$so = $hd['social'];
$br = $hd['brand'];
$kpi = fn(string $label, string $value, string $href, string $tone = '') => '<a class="hq-kpi ' . $tone . '" href="' . pm_h($href) . '"><span>' . pm_h($label) . '</span><b>' . pm_h($value) . '</b></a>';
$td = $hd['today'];
$needs = pm_home_needs($vb, $hd);
$nNeed = array_sum(array_map('count', $needs));
$tile = fn(string $label, string $value, string $note, string $href) => '<a class="hq-today-i" href="' . pm_h($href) . '"><span>' . pm_h($label) . '</span><b>' . pm_h($value) . '</b><small>' . pm_h($note) . '</small></a>';
?>
<section class="hq-hero">
  <div>
    <div class="hq-date"><?= pm_h(date('l j F')) ?></div>
    <h1><?= pm_h($greet . ($who !== '' ? ', ' . $who : '')) ?></h1>
    <p class="sub">How <b><?= pm_h($bname) ?></b> stands right now. <a href="?tab=guide">How the app works</a></p>
  </div>
</section>

<div class="hq-kpis">
  <?= $kpi('Leads', (string)$s['leads'], '?tab=agents') ?>
  <?= $kpi('Replies waiting', (string)$s['replies'], '?tab=agents', $s['replies'] > 0 ? 'warn' : '') ?>
  <?= $kpi('Follow-ups due', (string)$s['followups_due'], '?tab=agents#strategy', $s['followups_due'] > 0 ? 'warn' : '') ?>
  <?= $kpi('Posts lined up', (string)$so['planned'], '?tab=social') ?>
  <?= $kpi('Page health', $br['score'] === null ? '-' : $br['score'] . '%', '?tab=social&view=optimise', $br['score'] !== null && $br['score'] < 60 ? 'warn' : '') ?>
  <?= $kpi('Won', (string)$s['won'], '?tab=history') ?>
</div>

<section class="card hq-today" aria-label="Today">
  <?php $fg = $td['goal']; ?><?= $tile('Firm goal this month', $fg['signed'][0] . ' of ' . $fg['signed'][1] . ' signed', $fg['replies'][0] . ' of ' . $fg['replies'][1] . ' replies · ' . $fg['pace'], '?tab=social&view=results') ?>
  <?= $tile('Emails today', $td['sent'] . ($td['cap'] ? ' (plan ' . $td['cap'] . ')' : ''), $td['queue'] ? $td['queue'] . ' in the queue' . ($td['next'] === 'paused' ? ', sending paused' : ($td['next'] !== '' ? ', next ' . $td['next'] : '')) : 'nothing queued', '?tab=agents') ?>
  <?= $tile('WhatsApp today', $td['wa'] . ($td['wa_cap'] ? ' of ' . $td['wa_cap'] : ''), 'first messages and follow-ups', '?tab=whatsapp') ?>
  <?= $tile('Posts going out today', (string)$td['posts'], $so['ready'] ? $so['planned'] . ' lined up in all' : 'Facebook not connected', '?tab=social') ?>
  <?= $tile('Playbook', $td['pb_total'] ? $td['pb_done'] . ' of ' . $td['pb_total'] : '–', $td['pb_total'] ? 'tasks done today' : 'no playbook today', '?tab=social&view=growth') ?>
  <?= $tile('AI today', number_format($td['ai']) . ' tokens', ($td['ai_budget'] ? 'budget ' . number_format($td['ai_budget']) . ' (all businesses)' : 'no daily limit') . ($td['memory'] ? ' · ' . $td['memory'] . ' from memory' : ''), '?tab=social&view=results#ai') ?>
</section>

<?php $bd = function_exists('pm_board') ? pm_board($vb) : []; if (!empty($bd['reports'])): ?>
<section class="card" id="board" aria-label="Today's board">
  <h2>Today's board <span class="muted t-sm">· put together by <?= pm_h(pm_agent_first('director')) ?> with the department heads<?= !empty($bd['standup']) ? ' at the ' . pm_h($bd['standup']) . ' stand-up' : '' ?>, checked <?= pm_h(date('H:i', strtotime((string)$bd['at']))) ?></span></h2>
  <p class="hq-headline"><?= pm_h((string)$bd['headline']) ?></p>
  <?php if ($bd['you']): ?><div class="btns"><?php foreach ($bd['you'] as [$yt, $yh, $yu]): ?><a class="btn small<?= $yu ? ' primary' : '' ?>" href="<?= pm_h($yh) ?>"><?= pm_h($yt) ?></a><?php endforeach; ?></div><?php endif; ?>
  <ul class="talklist"><?php foreach ($bd['reports'] as [$rd, $rt, $rh]): ?><li><time></time><b><?= pm_h(pm_talk_name($rd)) ?>:</b><span><?= $rh !== '' ? '<a href="' . pm_h($rh) . '">' . pm_h($rt) . '</a>' : pm_h($rt) ?></span></li><?php endforeach; ?></ul>
  <?php if ($bd['checks']): ?><p class="hint"><b><?= pm_h(pm_agent_first('director')) ?> checked the board against what we have:</b></p><ul class="t-sm"><?php foreach ($bd['checks'] as [$ct, $ch]): ?><li><a href="<?= pm_h($ch) ?>"><?= pm_h($ct) ?></a></li><?php endforeach; ?></ul><?php endif; ?>
</section>
<?php endif; ?>

<div class="hq-top">
  <section class="card hq-brief" id="brief">
    <h2>Today's brief from the Director</h2>
    <?php $dir = pm_dm_as($vb, fn() => pm_director_brief(false, false)) + ['focus' => [], 'drop' => [], 'experiment' => '']; ?>
    <?php if ($brief['headline'] !== ''): ?>
      <p class="hq-headline"><?= pm_h($brief['headline']) ?></p>
      <?php if ($brief['priorities']): ?><ol class="hq-prio"><?php foreach (array_slice($brief['priorities'], 0, 5) as $p): ?><li><?= pm_h($p) ?></li><?php endforeach; ?></ol><?php endif; ?>
      <?php if ($dir['focus']): ?><p class="hint">Searching more of: <b><?= pm_h(implode(', ', $dir['focus'])) ?></b><?= $dir['drop'] ? ' · less of: ' . pm_h(implode(', ', $dir['drop'])) : '' ?></p><?php endif; ?>
      <?php if ($dir['experiment']): ?><p class="hint">Experiment this week: <?= pm_h($dir['experiment']) ?></p><?php endif; ?>
      <?php if (!empty($dir['meeting'])): ?><details class="more mt-1"><summary>This morning's sit-down (how the team decided)</summary><ul class="talklist"><?php foreach ($dir['meeting'] as $mt): ?><li><time></time><b><?= pm_h(pm_talk_name((string)$mt['who'])) ?>:</b><span><?= pm_h((string)$mt['says']) ?></span></li><?php endforeach; ?></ul>
        <?php if (!empty($dir['orders'])): ?><p class="hint"><b>Orders:</b> <?= pm_h(implode(' · ', array_map(fn($o) => pm_talk_name((string)$o['to']) . ': ' . $o['say'], $dir['orders']))) ?></p><?php endif; ?></details><?php endif; ?>
      <?php if ($brief['date'] !== '' && $brief['date'] !== date('Y-m-d')): ?><p class="hint">Written on <?= pm_h(date('j M', strtotime($brief['date']))) ?>.</p><?php endif; ?>
    <?php else: ?>
      <p class="muted">No strategy has been written yet. The Director reads the numbers once a day and says what to do first.</p>
    <?php endif; ?>
    <?= function_exists('pm_adv_home_line') ? pm_adv_home_line($vb) : '' ?>
    <?php $was = pm_brand(); pm_brand_set($vb); $miss = pm_brand_missing(pm_settings()); pm_brand_set($was); if ($miss): ?><p class="hint"><span class="chip warn">missing</span> <b><?= pm_h(implode(', ', array_column($miss, 'label'))) ?></b>: clients cannot use what is not filled in. <a href="?tab=settings&amp;section=business&amp;brand=<?= pm_h($vb) ?>#missing">Add them</a></p><?php endif; ?>
    <form method="post" class="btns"><input type="hidden" name="csrf" value="<?= pm_h((string)($_SESSION['csrf'] ?? '')) ?>"><input type="hidden" name="action" value="agents"><input type="hidden" name="do" value="director"><input type="hidden" name="bt" value="home">
      <button class="btn small<?= $brief['headline'] === '' ? ' primary' : '' ?>"><?= $brief['headline'] === '' ? "Write today's strategy" : 'Refresh strategy' ?></button></form>
  </section>
  <section class="card hq-need">
    <h2>What needs you <span class="muted t-sm"><?= $nNeed ? '· ' . $nNeed . ' thing' . ($nNeed === 1 ? '' : 's') : '· nothing right now' ?></span></h2>
    <?php foreach ($cards as $k => $c): $items = $needs[$k] ?? []; ?>
      <div class="hq-needdept" style="<?= pm_h(pm_ui_dept_style($k)) ?>">
        <a class="hq-needrow <?= $items ? ($items[0][2] ? 'warn' : 'todo') : 'ok' ?>" href="<?= pm_h($items[0][1] ?? $reg[$k]['href']) ?>"><span class="dico"><?= pm_ui_icon($k, 16) ?></span><span><b><?= pm_h($reg[$k]['name']) ?></b><small><i class="sdot <?= $items ? 'warn' : 'ok' ?>"></i><?= pm_h($items ? $items[0][0] : ($k === 'insights' ? $c['text'] : 'All clear')) ?></small></span><i class="go">&rsaquo;</i></a>
        <?php foreach (array_slice($items, 1) as $it): ?><a class="hq-needsub" href="<?= pm_h($it[1]) ?>"><i class="sdot <?= $it[2] ? 'warn' : '' ?>"></i><?= pm_h($it[0]) ?></a><?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </section>
</div>

<?php if (count(pm_user_brands()) > 1): ?>
<h3>Your businesses</h3>
<div class="hq-biz">
<?php foreach (pm_user_brands() as $bid): $g = pm_home_glance($bid); ?>
  <a class="hq-bizrow<?= $bid === $vb ? ' on' : '' ?>" href="?tab=home&amp;brand=<?= pm_h($bid) ?>" style="--bz:<?= pm_h(pm_ui_accent($settings, $bid)) ?>">
    <i class="dot" style="background:<?= pm_h(pm_ui_accent($settings, $bid)) ?>"></i><b><?= pm_h(pm_brand_title($settings, $bid)) ?></b>
    <span><?= (int)$g['leads'] ?> leads</span><span class="<?= $g['replies'] ? 'warn' : '' ?>"><?= (int)$g['replies'] ?> replies waiting</span><span class="<?= $g['posts_waiting'] ? 'warn' : '' ?>"><?= (int)$g['posts_waiting'] ?> posts to approve</span><span><?= $g['fb'] ? 'Facebook connected' : 'Facebook not connected' ?></span>
  </a>
<?php endforeach; ?>
</div>
<?php endif; ?>

<h3>Latest from the team</h3>
<section class="card hq-feed">
  <?php if (!$hd['activity']): ?><p class="muted">Nothing yet. When the agents run, what they did is written here.</p>
  <?php else: ?><ul><?php foreach (array_filter($hd['activity'], fn($a) => $a['agent'] !== 'Director') as $a): ?>
    <li class="<?= $a['error'] ? 'err' : '' ?>"><time><?= pm_h($a['at'] !== '' ? date('j M H:i', strtotime($a['at'])) : '') ?></time><b><?= pm_h($a['agent']) ?></b><span><?= pm_h(mb_substr($a['msg'], 0, 220)) ?></span></li>
  <?php endforeach; ?></ul><?php endif; ?>
</section>

<?php $tbRows = function_exists('pm_tb_rows') ? array_filter(pm_tb_rows($vb, 30), fn($r) => empty($r['struck'])) : []; ?>
<h3 id="teamtalk">How the team is talking</h3>
<section class="card hq-feed">
  <p class="hint mt-0">The departments pass notes to each other (no AI): what replies taught the Writers, what you turned down and why, the Director's orders for the day. Each agent reads the notes addressed to it. Strike a note that is wrong and they stop reading it.</p>
  <?php if (!$tbRows): ?><p class="muted">No notes yet. They appear as replies come in, as you turn drafts down, and when the Director plans the day.</p>
  <?php else: $csrfH = pm_h((string)($_SESSION['csrf'] ?? '')); ?><ul><?php foreach (array_slice($tbRows, 0, 15) as $r): ?>
    <li><time><?= pm_h(date('j M H:i', strtotime((string)$r['at']))) ?></time><b><?= pm_h((PM_TB_DEPTS[$r['from']] ?? $r['from']) . ' → ' . (PM_TB_DEPTS[$r['to']] ?? $r['to'])) ?></b><span><?= pm_h((string)$r['text']) ?></span>
      <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= $csrfH ?>"><input type="hidden" name="action" value="agents"><input type="hidden" name="do" value="tb_strike"><input type="hidden" name="bt" value="home"><input type="hidden" name="note" value="<?= pm_h((string)$r['id']) ?>"><button class="btn small ghost" title="The agents stop reading this note">Strike</button></form></li>
  <?php endforeach; ?></ul><?php endif; ?>
</section>
