<?php
/**
 * Brand > Relearn the business: study the business again with one press (lib/relearn.php), watch the agents work, then approve, edit or turn down each change.
 * Shares index.php's variables ($vb, $settings, $csrf).
 */
pm_brand_set($vb);
$st = pm_rl_state($vb);
$busy = pm_rl_busy($vb);
$props = (array)($st['proposals'] ?? []);
$fx = fn(string $do, string $extra = '') => '<input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="social_ext"><input type="hidden" name="do" value="' . $do . '">' . $extra;
$names = function_exists('pm_agent_names') ? pm_agent_names() : [];
?>
<?= pm_social_nav('relearn') ?>
<div class="card">
  <h2 class="mt-0">Relearn <?= pm_h(pm_brand_name($vb)) ?></h2>
  <p class="hint mt-0">The team studies the business again from scratch: the website (up to five pages), a web search for its own pages, listings, reviews and news, our Facebook Page and WhatsApp Business profile. They compare everything with what the app knows and suggest each change separately: contact details we are missing or that differ, what the business is and who buys, things it offers that our list leaves out, new facts (each with where it was found), pages about it online, who to look for and towns. Nothing changes until you approve it. One web search and one AI call.</p>
  <?php if ($busy): ?>
    <p><b>The team is working on it</b> (started <?= pm_h(date('H:i', strtotime((string)$st['started']))) ?>). This page refreshes by itself.</p>
    <script>setTimeout(function () { location.reload(); }, 6000);</script>
  <?php else: ?>
    <form method="post"><?= $fx('rl_start') ?><div class="btns mt-0"><button class="btn primary" <?= pm_agents_ready() ? '' : 'disabled title="Add an AI key to .env first"' ?> <?= $props ? 'onclick="return confirm(\'Study it again? The changes still waiting below will be replaced by the new study.\')"' : '' ?>>Relearn the business</button>
      <?php if (!empty($st['at'])): ?><span class="muted t-sm">Last studied <?= pm_h(date('j M Y H:i', strtotime((string)$st['at']))) ?> by <?= pm_h((string)($st['by'] ?? '')) ?></span><?php endif; ?></div></form>
  <?php endif; ?>
  <?php if (($st['state'] ?? '') === 'failed'): ?><p class="flash err">The study stopped: <?= pm_h((string)($st['error'] ?? 'unknown error')) ?>. Press Relearn to try again.</p><?php endif; ?>
  <?php if (!empty($st['log'])): ?>
    <ul class="talklist mt-2"><?php foreach ((array)$st['log'] as [$who, $text]): ?><li><time></time><b><?= pm_h($names[$who] ?? ucfirst($who)) ?>:</b><span><?= pm_h($text) ?></span></li><?php endforeach; ?></ul>
  <?php endif; ?>
</div>

<?php if (!$busy && $props): ?>
<form method="post" id="rl-form"><?= $fx('rl_decide') ?>
<div class="card">
  <h2 class="mt-0">Changes waiting for your approval (<?= count($props) ?>)</h2>
  <p class="hint mt-0">Tick what to approve; you can change any value first. A change you do not tick stays here for later. "Turn down" removes it for good, and it is not suggested again.</p>
  <div class="btns"><button type="button" class="btn small" onclick="document.querySelectorAll('#rl-form input[name=\'ok[]\']').forEach(function (c) { c.checked = true; })">Tick all</button>
    <button type="button" class="btn small" onclick="document.querySelectorAll('#rl-form input[name=\'ok[]\']').forEach(function (c) { c.checked = false; })">Untick all</button></div>
</div>
<?php foreach (PM_RL_GROUPS as $gk => $gl): $rows = array_filter($props, fn($p) => $p['group'] === $gk); if (!$rows) continue; ?>
<div class="card">
  <h2 class="mt-0"><?= pm_h($gl) ?> <span class="muted t-sm">· <?= count($rows) ?></span></h2>
  <?php foreach ($rows as $id => $p): $long = mb_strlen($p['value']) > 90; ?>
  <div class="sx-card my-2">
    <label class="check" style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="ok[]" value="<?= pm_h($id) ?>"> <b><?= pm_h($p['label']) ?></b>
      <span class="sx-chip <?= $p['kind'] === 'change' ? 'warn' : '' ?>"><?= $p['kind'] === 'change' ? 'differs from ours' : 'new' ?></span></label>
    <?php if ($p['current'] !== ''): ?><p class="hint my-1">Now: <?= pm_h(mb_substr($p['current'], 0, 400)) ?></p><?php endif; ?>
    <?php if ($long): ?><textarea name="val[<?= pm_h($id) ?>]" rows="3"><?= pm_h($p['value']) ?></textarea><?php else: ?><input type="text" name="val[<?= pm_h($id) ?>]" value="<?= pm_h($p['value']) ?>"><?php endif; ?>
    <p class="hint my-1"><?= pm_h($p['why']) ?><?= $p['src'] !== '' ? ' Found in: ' . (str_starts_with($p['src'], 'http') ? '<a href="' . pm_h($p['src']) . '" target="_blank" rel="noopener">' . pm_h(pm_study_host($p['src'])) . '</a>' : pm_h($p['src'])) : '' ?>
      <?= $p['group'] === 'presence' ? ' <a href="' . pm_h($p['value']) . '" target="_blank" rel="noopener">Open it</a>' : '' ?></p>
    <label class="check t-sm"><input type="checkbox" name="no[]" value="<?= pm_h($id) ?>"> Turn down</label>
  </div>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>
<div class="btns formbar"><button class="btn primary">Apply what I approved</button><button class="btn" name="reject_rest" value="1" onclick="return confirm('Apply what you ticked and turn down everything else for good?')">Apply, and turn down the rest</button></div>
</form>
<?php elseif (!$busy && ($st['state'] ?? '') === 'done'): ?>
<div class="card"><p class="mt-0">Nothing waits for your approval.<?= !empty($st['decided']) ? ' ' . count(array_filter((array)$st['decided'], fn($d) => $d === 'approved')) . ' approved and ' . count(array_filter((array)$st['decided'], fn($d) => $d === 'rejected')) . ' turned down from the last study.' : '' ?></p></div>
<?php endif; ?>

<?php if (!$busy && (!empty($st['gaps']) || !empty($st['questions']))): ?>
<div class="card">
  <h2 class="mt-0">What only you can fix or answer</h2>
  <?php if (!empty($st['gaps'])): ?><ul><?php foreach ((array)$st['gaps'] as [$gt, $gh]): ?><li><a href="<?= pm_h($gh) ?>"><?= pm_h($gt) ?></a></li><?php endforeach; ?></ul><?php endif; ?>
  <?php if (!empty($st['questions'])): ?><p class="hint">Questions the team could not answer from the pages (answer them in Brand › Voice or Sales › Targeting and messages, then relearn):</p>
    <ul><?php foreach ((array)$st['questions'] as $q): ?><li><?= pm_h((string)$q['q']) ?><?= !empty($q['why']) ? ' <span class="muted t-sm">· ' . pm_h((string)$q['why']) . '</span>' : '' ?></li><?php endforeach; ?></ul><?php endif; ?>
  <?php if (!empty($st['note'])): ?><p class="hint"><?= pm_h((string)$st['note']) ?></p><?php endif; ?>
</div>
<?php endif; ?>
