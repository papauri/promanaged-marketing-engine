<?php
/**
 * The agency overview (tab=home): the marketing firm on one screen. A greeting, today's brief from the Director, the numbers that matter, what needs the owner now, one card per department
 * (its state, its numbers, its team and the next steps), every business at a glance, and the latest lines of the team's diary. Everything is read from stored data: no AI, no network, instant.
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
$initials = fn(string $n) => mb_strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($n)) ?: [], 0, 2))));
$kpi = fn(string $label, string $value, string $href, string $tone = '') => '<a class="hq-kpi ' . $tone . '" href="' . pm_h($href) . '"><span>' . pm_h($label) . '</span><b>' . pm_h($value) . '</b></a>';
$need = array_filter($cards, fn($c) => $c['state'] !== 'ok');
?>
<section class="hq-hero">
  <div>
    <div class="hq-date"><?= pm_h(date('l j F')) ?></div>
    <h1><?= pm_h($greet . ($who !== '' ? ', ' . $who : '')) ?></h1>
    <p class="sub">How the <b><?= pm_h($bname) ?></b> marketing firm stands right now. Every number comes from your own records, so this page opens at once.</p>
  </div>
  <div class="headacts"><a class="btn" href="?tab=agents">Open the leads</a><a class="btn primary" href="?tab=social">Open the content plan</a></div>
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
    <?php if ($brief['headline'] !== ''): ?>
      <p class="hq-headline"><?= pm_h($brief['headline']) ?></p>
      <?php if ($brief['priorities']): ?><ol class="hq-prio"><?php foreach (array_slice($brief['priorities'], 0, 5) as $p): ?><li><?= pm_h($p) ?></li><?php endforeach; ?></ol><?php endif; ?>
      <?php if ($brief['date'] !== '' && $brief['date'] !== date('Y-m-d')): ?><p class="hint">Written on <?= pm_h(date('j M', strtotime($brief['date']))) ?>. Open the leads and press "Refresh strategy" for today's.</p><?php endif; ?>
    <?php else: ?>
      <p class="muted">No strategy has been written yet. The Director reads the numbers once a day and says what to do first.</p>
      <div class="btns"><a class="btn small primary" href="?tab=agents#strategy">Write today's strategy</a></div>
    <?php endif; ?>
  </section>
  <section class="card hq-need">
    <h2>Needs you now</h2>
    <?php if (!$need): ?><p class="hq-clear"><i></i>Nothing is waiting on you. The team is on it.</p>
    <?php else: foreach ($need as $k => $c): ?>
      <a class="hq-needrow" href="<?= pm_h($c['next'][0][1]) ?>" style="<?= pm_h(pm_ui_dept_style($k)) ?>"><span class="dico"><?= pm_ui_icon($k, 16) ?></span><span><b><?= pm_h($reg[$k]['label']) ?></b><small><?= pm_h($c['text']) ?></small></span><i class="go">&rsaquo;</i></a>
    <?php endforeach; endif; ?>
  </section>
</div>

<h3>The departments</h3>
<div class="hq-depts">
<?php foreach ($cards as $k => $c): $d = $reg[$k]; ?>
  <article class="dept" style="<?= pm_h(pm_ui_dept_style($k)) ?>">
    <header>
      <span class="dico"><?= pm_ui_icon($k, 20) ?></span>
      <div><h2><a href="<?= pm_h($d['href']) ?>"><?= pm_h($d['name']) ?></a></h2><small><?= pm_h($c['mission']) ?></small></div>
    </header>
    <p class="dsay <?= pm_h($c['state']) ?>"><i></i><?= pm_h($c['text']) ?></p>
    <dl class="dstats"><?php foreach ($c['stats'] as $label => $value): ?><div><dt><?= pm_h((string)$label) ?></dt><dd><?= pm_h((string)$value) ?></dd></div><?php endforeach; ?></dl>
    <details class="dstaff">
      <summary><span class="avs"><?php foreach ($c['staff'] as $m): ?><span class="av<?= $m['on'] ? '' : ' off' ?>" title="<?= pm_h($m['name']) ?>"><?= pm_h($initials($m['name'])) ?></span><?php endforeach; ?></span><span><?= (int)$c['on_duty'] ?> of <?= count($c['staff']) ?> on duty</span></summary>
      <ul class="roster"><?php foreach ($c['staff'] as $m): ?>
        <li class="<?= $m['on'] ? 'on' : 'off' ?>"><span class="av<?= $m['on'] ? '' : ' off' ?>"><?= pm_h($initials($m['name'])) ?></span><div><b><?= pm_h($m['name']) ?></b><small><?= pm_h($m['what']) ?></small></div><em><?= $m['on'] ? 'on duty' : pm_h($m['why']) ?></em></li>
      <?php endforeach; ?></ul>
    </details>
    <div class="dacts"><?php foreach ($c['next'] as $i => [$label, $href]): ?><a class="btn small<?= $i === 0 ? ' primary' : '' ?>" href="<?= pm_h($href) ?>"><?= pm_h($label) ?></a><?php endforeach; ?></div>
  </article>
<?php endforeach; ?>
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
  <?php else: ?><ul><?php foreach ($hd['activity'] as $a): ?>
    <li class="<?= $a['error'] ? 'err' : '' ?>"><time><?= pm_h($a['at'] !== '' ? date('j M H:i', strtotime($a['at'])) : '') ?></time><b><?= pm_h($a['agent']) ?></b><span><?= pm_h(mb_substr($a['msg'], 0, 220)) ?></span></li>
  <?php endforeach; ?></ul><?php endif; ?>
</section>
