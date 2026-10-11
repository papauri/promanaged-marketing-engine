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
?>
<section class="hq-hero">
  <div>
    <div class="hq-date"><?= pm_h(date('l j F')) ?></div>
    <h1><?= pm_h($greet . ($who !== '' ? ', ' . $who : '')) ?></h1>
    <p class="sub">How <b><?= pm_h($bname) ?></b> stands right now.</p>
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

<div class="hq-top">
  <section class="card hq-brief" id="brief">
    <h2>Today's brief from the Director</h2>
    <?php $dir = pm_dm_as($vb, fn() => pm_director_brief()) + ['focus' => [], 'drop' => [], 'experiment' => '']; ?>
    <?php if ($brief['headline'] !== ''): ?>
      <p class="hq-headline"><?= pm_h($brief['headline']) ?></p>
      <?php if ($brief['priorities']): ?><ol class="hq-prio"><?php foreach (array_slice($brief['priorities'], 0, 5) as $p): ?><li><?= pm_h($p) ?></li><?php endforeach; ?></ol><?php endif; ?>
      <?php if ($dir['focus']): ?><p class="hint">Searching more of: <b><?= pm_h(implode(', ', $dir['focus'])) ?></b><?= $dir['drop'] ? ' · less of: ' . pm_h(implode(', ', $dir['drop'])) : '' ?></p><?php endif; ?>
      <?php if ($dir['experiment']): ?><p class="hint">Experiment this week: <?= pm_h($dir['experiment']) ?></p><?php endif; ?>
      <?php if ($brief['date'] !== '' && $brief['date'] !== date('Y-m-d')): ?><p class="hint">Written on <?= pm_h(date('j M', strtotime($brief['date']))) ?>.</p><?php endif; ?>
    <?php else: ?>
      <p class="muted">No strategy has been written yet. The Director reads the numbers once a day and says what to do first.</p>
    <?php endif; ?>
    <form method="post" class="btns"><input type="hidden" name="csrf" value="<?= pm_h((string)($_SESSION['csrf'] ?? '')) ?>"><input type="hidden" name="action" value="agents"><input type="hidden" name="do" value="director"><input type="hidden" name="bt" value="home">
      <button class="btn small<?= $brief['headline'] === '' ? ' primary' : '' ?>"><?= $brief['headline'] === '' ? "Write today's strategy" : 'Refresh strategy' ?></button></form>
  </section>
  <section class="card hq-need">
    <h2>The departments</h2>
    <?php foreach ($cards as $k => $c): ?>
      <a class="hq-needrow <?= pm_h($c['state']) ?>" href="<?= pm_h($c['next'][0][1] ?? $reg[$k]['href']) ?>" style="<?= pm_h(pm_ui_dept_style($k)) ?>"><span class="dico"><?= pm_ui_icon($k, 16) ?></span><span><b><?= pm_h($reg[$k]['name']) ?></b><small><i class="sdot <?= pm_h($c['state']) ?>"></i><?= pm_h($c['text']) ?></small></span><i class="go">&rsaquo;</i></a>
    <?php endforeach; ?>
  </section>
</div>

<?php if (count(pm_brand_ids()) > 1): ?>
<h3>Your businesses</h3>
<div class="hq-biz">
<?php foreach (pm_brand_ids() as $bid): $g = pm_home_glance($bid); ?>
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
