<?php
/** Social > Optimise: a checklist per channel, a ready suggestion for every gap, applied with one press where the channel allows it. No AI unless "Polish wording" is pressed. */
pm_brand_set($vb);
$bname = pm_brand_title($settings, $vb);
$run = pm_opt_run($vb);
$st = pm_opt_store($vb);
$cfg = pm_channels_cfg($vb);
$fx = fn(string $do, string $extra = '') => '<input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="social_ext"><input type="hidden" name="do" value="' . pm_h($do) . '">' . $extra;
$hid = fn(string $n, string $v) => '<input type="hidden" name="' . pm_h($n) . '" value="' . pm_h($v) . '">';
$hist = (array)$st['history'];
ksort($hist);
$prevScore = null;
$prevDate = '';
foreach (array_reverse($hist, true) as $d => $sc) {
    if ($d < date('Y-m-d')) {
        $prevScore = (int)$sc;
        $prevDate = $d;
        break;
    }
}
$delta = $prevScore === null ? null : (int)$run['score'] - $prevScore;
$bulk = [];
foreach ($run['channels'] as $c) {
    foreach ($c['items'] as $i) {
        if ($i['state'] !== 'ok' && $i['bulk'] && in_array($i['how'], ['api', 'app'], true)) {
            $bulk[] = $c['label'] . ': ' . $i['title'];
        }
    }
}
$confirmAll = "Apply these now?\n\n" . implode("\n", array_slice($bulk, 0, 14)) . "\n\nPosts are added as drafts. Nothing is published.";
$item = fn(array $i): string => pm_opt_item_html($i, $vb, $csrf);
?>
<?= pm_social_nav('optimise') ?>
<style>
.opt-it{margin:8px 0}.opt-it .sxh{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.opt-it input[type=text],.opt-it textarea,.opt-it select{width:100%;margin-top:6px}
.opt-pre{white-space:pre-wrap;word-break:break-word;margin:8px 0;padding:9px 11px;background:var(--bg,#f6f7f8);border-radius:8px;font:13px/1.45 -apple-system,"Segoe UI",Roboto,Arial,sans-serif}
.opt-hours>div{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:4px 0}.opt-hours .check{min-width:130px}.opt-hours input[type=time]{width:auto}
.opt-score{font-size:34px;font-weight:700;line-height:1}.opt-top{display:flex;gap:18px;align-items:center;flex-wrap:wrap}.opt-chans{display:flex;gap:6px;flex-wrap:wrap;margin:10px 0 0}
.opt-chans a{text-decoration:none}.opt-sum{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
</style>
<div class="card">
  <div class="opt-top">
    <div><span class="opt-score"><?= (int)$run['score'] ?>%</span>
      <span class="muted"> page health · <?= pm_h(date('j M H:i', strtotime($run['at']))) ?><?= $delta !== null ? ' · ' . ($delta >= 0 ? '+' : '') . $delta . ' since ' . pm_h(date('j M', strtotime($prevDate))) : '' ?></span></div>
    <div class="btns" style="margin:0">
      <form method="post" class="inl"><?= $fx('opt_refresh') ?><button class="btn small" title="Reads the Page, Instagram and WhatsApp profiles again">Check again</button></form>
      <?php if ($bulk): ?>
      <form method="post" class="inl" onsubmit="return confirm(<?= pm_h(json_encode($confirmAll)) ?>)"><?= $fx('opt_all') ?><button class="btn small primary">Apply the <?= count($bulk) ?> ready suggestion<?= count($bulk) === 1 ? '' : 's' ?></button></form>
      <?php endif; ?>
      <form method="post" class="inl"><?= $fx('opt_polish') ?><button class="btn small" <?= pm_agents_ready() ? '' : 'disabled title="Add an AI key to .env first"' ?> title="One small AI call rewords the text suggestions. It may not add a fact, number or link: anything that does is thrown away.">Polish the wording (1 AI call)</button></form>
      <?php if (!empty($st['ai'])): ?><form method="post" class="inl"><?= $fx('opt_plain') ?><button class="btn small">Back to plain wording</button></form><?php endif; ?>
    </div>
  </div>
  <p class="hint" style="margin:8px 0 0">Everything here is worked out from <?= pm_h($bname) ?>'s own details and facts, with no AI. Each suggestion is shown for you to read and edit before it is applied; nothing is posted or sent.</p>
  <div class="opt-chans">
    <?php foreach ($run['channels'] as $k => $c): ?><a href="#c-<?= pm_h($k) ?>" class="sx-chip <?= $c['score'] === null ? '' : ($c['score'] >= 85 ? 'ok' : ($c['score'] >= 55 ? 'warn' : 'bad')) ?>"><?= pm_h($c['label']) ?><?= $c['score'] === null ? (empty($c['connected']) ? ' · not connected' : '') : ' ' . (int)$c['score'] . '%' ?></a><?php endforeach; ?>
  </div>
  <?php if (count($hist) > 1): ?><p class="hint" style="margin:8px 0 0">Recent: <?= pm_h(implode(' → ', array_map(fn($d, $s) => date('j M', strtotime($d)) . ' ' . (int)$s . '%', array_keys(array_slice($hist, -6, null, true)), array_slice($hist, -6, null, true)))) ?></p><?php endif; ?>
</div>

<?php foreach ($run['channels'] as $k => $c):
    $todo = array_values(array_filter($c['items'], fn($i) => $i['state'] !== 'ok'));
    $good = array_values(array_filter($c['items'], fn($i) => $i['state'] === 'ok'));
    usort($todo, fn($a, $b) => [$b['state'] === 'missing' ? 1 : 0, $b['weight']] <=> [$a['state'] === 'missing' ? 1 : 0, $a['weight']]); ?>
<details class="card" id="c-<?= pm_h($k) ?>" <?= $todo || $c['note'] !== '' ? 'open' : '' ?>>
  <summary class="opt-sum"><b><?= pm_h($c['label']) ?></b>
    <?php if ($c['score'] !== null): ?><span class="sx-chip <?= $c['score'] >= 85 ? 'ok' : ($c['score'] >= 55 ? 'warn' : 'bad') ?>"><?= (int)$c['score'] ?>%</span><?php endif; ?>
    <span class="muted"><?= $todo ? count($todo) . ' to improve' : ($c['items'] ? 'all good' : '') ?><?= $good ? ' · ' . count($good) . ' good' : '' ?></span></summary>
  <?php if ($c['note'] !== ''): ?><p class="hint"><?= pm_h($c['note']) ?></p><?php endif; ?>
  <?php foreach ($todo as $i): ?><?= $item($i) ?><?php endforeach; ?>
  <?php if ($good): ?><details class="more"><summary class="hint" style="cursor:pointer">Already good (<?= count($good) ?>)</summary>
    <ul class="opt-good"><?php foreach ($good as $i): ?><li><b><?= pm_h($i['title']) ?></b><?= $i['current'] !== '' ? ' <span class="muted">· ' . pm_h(mb_substr($i['current'], 0, 120)) . '</span>' : '' ?>
      <?php if ($i['tick'] !== '' && in_array($i['how'], ['manual', 'go'], true)): ?><form method="post" class="inl"><?= $fx('opt_done', $hid('tick', $i['tick']) . $hid('how', 'undo')) ?><button class="btn small">Undo tick</button></form><?php endif; ?></li><?php endforeach; ?></ul></details><?php endif; ?>
</details>
<?php endforeach; ?>

<div class="card">
  <h2>Channels you use</h2>
  <p class="hint">Facebook, Instagram, WhatsApp (when connected) and Google are always checked. Tick the others you have a profile on and they get a checklist too.</p>
  <form method="post"><?= $fx('opt_use') ?>
    <?php foreach (['x' => 'X', 'linkedin' => 'LinkedIn', 'tiktok' => 'TikTok', 'youtube' => 'YouTube'] as $k => $l): ?>
    <label class="check"><input type="checkbox" name="use[<?= $k ?>]" value="1" <?= !empty($cfg['ticks']['use_' . $k]) || ($k === 'x' && pm_x_cfg($vb)['ready']) || ($k === 'linkedin' && !empty(pm_social_settings($vb)['channels']['linkedin'])) ? 'checked' : '' ?>> <?= pm_h($l) ?></label>
    <?php endforeach; ?>
    <div class="btns"><button class="btn small">Save</button></div>
  </form>
</div>

<?php if (!empty($st['log'])): ?>
<div class="card"><h2>What was applied</h2>
  <table class="sx-table"><?php foreach (array_slice((array)$st['log'], 0, 10) as $l): ?>
    <tr><td style="white-space:nowrap"><?= pm_h($l['at']) ?></td><td><?= pm_h(PM_OPT_CHANNELS[$l['channel']] ?? $l['channel']) ?></td><td><span class="sx-chip <?= $l['ok'] ? 'ok' : 'bad' ?>"><?= $l['ok'] ? 'done' : 'failed' ?></span> <?= pm_h($l['msg']) ?></td></tr>
  <?php endforeach; ?></table>
</div>
<?php endif; ?>
