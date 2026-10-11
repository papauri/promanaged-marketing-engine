<?php
/**
 * Social > Content: what the business posts about. The pillars and the mix between them, the idea bank (the owner's ideas beside the built-in starters), the proof the planner may quote,
 * the hashtags, and which old posts to bring back. The planner reads all of this when it writes a week. Needs $vb, $settings, $csrf.
 */
pm_brand_set($vb);
$bname = pm_brand_title($settings, $vb);
$bank = pm_bank_get($vb);
$mine = pm_content_ideas($vb);
$pil = pm_social_pillars($vb);
$names = array_map(fn($p) => (string)$p['name'], $pil);
$pilRows = $pil;
$pilRows[] = ['name' => '', 'weight' => ''];
$proofAll = pm_social_proof_all($vb);
$proofUsable = count(array_filter($proofAll, fn($r) => !empty($r['consent']) && trim((string)($r['text'] ?? '')) !== ''));
$proofRows = $proofAll;
$proofRows[] = ['type' => 'quote'];
$mix = pm_sx_mix($vb);
$rec = pm_recycle_candidates($vb);
$irOpts = pm_swarm_waiting($vb, 'ideas');
$num = fn($v, $dash = '–') => $v === null || $v === '' ? $dash : number_format((float)$v);
$sx = fn(string $do, string $extra = '') => '<input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="social_ext"><input type="hidden" name="do" value="' . pm_h($do) . '">' . $extra;
$sxx = '<input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="social_x"><input type="hidden" name="back" value="social&amp;view=content">';
?>
<?= pm_social_nav('content') ?>

<div class="card" id="pillars">
  <h2>Pillars: the kinds of post, and how often</h2>
  <p class="hint mt-0">The planner writes exactly this mix (weights are shares, not percentages that must add to 100). Proof and Offer posts only happen when the proof bank below has a usable item.</p>
  <form method="post"><?= $sxx ?>
    <?php foreach ($pilRows as $r): ?><div class="row pillrow"><div><input type="text" name="pillar_name[]" value="<?= pm_h((string)$r['name']) ?>" placeholder="Pillar name" aria-label="Pillar name"></div><div><input type="number" min="0" max="100" name="pillar_weight[]" value="<?= pm_h((string)$r['weight']) ?>" placeholder="Weight" aria-label="Weight"></div></div><?php endforeach; ?>
    <div class="btns"><button class="btn small primary" name="do" value="pillars_save">Save the pillars</button><button class="btn small" name="do" value="pillars_reset">Back to the defaults</button></div>
  </form>
</div>

<?php if ($mix): ?><div class="card" id="mix">
  <h2>Suggested mix, from what worked</h2>
<?php if (!$mix): ?><p class="hint m-0">Not enough results yet: at least 8 scored posts, and 3 in each of two pillars, are needed before a change is suggested. The scoreboard is on <a href="?tab=social&amp;view=results">Insights &gt; Results</a>.</p>
<?php else: ?>
  <div class="rs-wrap"><table class="grid compact"><tr><th>Pillar</th><th class="n">Now</th><th class="n">Suggested</th><th class="n">Posts scored</th><th class="n">Avg engagement</th></tr>
  <?php foreach ($mix as $name => $m): ?><tr><td><?= pm_h($name) ?></td><td class="n"><?= round($m['old'] * 100) ?>%</td><td class="n"><b><?= round($m['new'] * 100) ?>%</b></td><td class="n"><?= (int)$m['n'] ?></td><td class="n"><?= $m['avg'] ?? '–' ?></td></tr><?php endforeach; ?>
  </table></div>
  <form method="post" class="btns"><?= $sx('apply_weights') ?><button class="btn primary small" data-confirm="Change the pillars to these shares? You can change them back above.">Apply the suggested mix</button>
    <span class="hint">70% of today's mix + 30% of what performed, at least 10% for every pillar. Nothing changes until you press the button.</span></form>
<?php endif; ?>
</div><?php endif; ?>

<div class="card" id="ideas">
  <h2>Idea bank</h2>
  <p class="hint mt-0">Ideas the planner may turn into posts. Yours sit beside the built-in starters for <?= pm_h($bname) ?>. Ideas the Audit suggests are added here too.</p>
  <div class="ideaboom">
    <div class="crew-row"><?php foreach (array_keys(PM_IR_SPECIALISTS) as $sp): ?><span class="crew-chip<?= pm_agents_ready() ? '' : ' off' ?>"><i></i><?= pm_h($sp) ?></span><?php endforeach; ?><span class="crew-chip"><i></i>Idea editor</span></div>
    <form method="post" class="findrow bt-run"><?= $sx('ir_run') ?><button class="btn"><?= $irOpts ? 'Ask the idea room again' : 'Ask the idea room for ideas' ?></button>
      <span class="hint m-0"><?= pm_agents_ready() ? 'Four specialists, one AI call; an editor scores every idea.' : 'No AI key: ideas are built from your own facts.' ?></span></form>
    <?php foreach ($irOpts as $o): ?>
    <div class="idearow">
      <span><span class="sx-chip"><?= pm_h((string)$o['by']) ?></span> <?= pm_h((string)$o['text']) ?> <span class="sx-chip <?= (int)$o['score'] >= PM_IR_THRESHOLD ? 'ok' : 'warn' ?>"><?= (int)$o['score'] ?>/100</span><?php foreach (array_slice((array)$o['issues'], 0, 1) as $is): ?> <span class="sx-chip warn" title="<?= pm_h((string)$is) ?>">to watch</span><?php endforeach; ?></span>
      <form method="post" class="inl"><?= $sx('ir_add', '<input type="hidden" name="id" value="' . pm_h((string)$o['id']) . '">') ?><button class="btn small primary">Add to my ideas</button></form>
      <form method="post" class="inl"><?= $sx('ir_dismiss', '<input type="hidden" name="id" value="' . pm_h((string)$o['id']) . '">') ?><button class="btn small ghost">Put aside</button></form>
    </div>
    <?php endforeach; ?>
  </div>
  <form method="post" class="findrow"><?= $sx('idea_add') ?>
    <input type="text" name="text" maxlength="240" placeholder="An idea for a post, in a sentence or two" aria-label="New idea" style="flex:1 1 320px" required>
    <select name="pillar" aria-label="Pillar" class="narrow"><option value="">Any pillar</option><?php foreach ($names as $n): ?><option><?= pm_h($n) ?></option><?php endforeach; ?></select>
    <button class="btn primary">Add the idea</button>
  </form>
  <?php if (!$mine): ?><p class="hint">No ideas of your own yet.</p><?php endif; ?>
  <?php foreach ($mine as $i): $on = ($i['active'] ?? true) !== false; ?>
  <div class="idearow<?= $on ? '' : ' off' ?>">
    <span><?= pm_h((string)$i['text']) ?><?= !empty($i['pillar']) ? ' <span class="sx-chip">' . pm_h((string)$i['pillar']) . '</span>' : '' ?><?= $on ? '' : ' <span class="sx-chip warn">paused</span>' ?></span>
    <form method="post" class="inl"><?= $sx('idea_toggle', '<input type="hidden" name="id" value="' . pm_h((string)$i['id']) . '">') ?><button class="btn small"><?= $on ? 'Pause' : 'Use again' ?></button></form>
    <form method="post" class="inl"><?= $sx('idea_delete', '<input type="hidden" name="id" value="' . pm_h((string)$i['id']) . '">') ?><button class="btn small danger" data-confirm="Delete this idea?">Delete</button></form>
  </div>
  <?php endforeach; ?>
  <form method="post" class="btns"><?= $sx('idea_builtin') ?><label class="check"><input type="checkbox" name="on" value="1" <?= !empty($bank['builtin_ideas']) ? 'checked' : '' ?> onchange="this.form.requestSubmit()"> Also use the built-in starter ideas</label><noscript><button class="btn small">Save</button></noscript></form>
</div>

<div class="card" id="proof">
  <h2>Proof bank</h2>
  <p class="hint mt-0">Real quotes, results, photos and offers the planner may use. Text is posted word for word. A quote or result is used only with the client's consent ticked; offers need an end date to get a "last days" post and stop being used after it. Clients who sign get a blank row here to ask for a one-line quote.</p>
  <form method="post"><?= $sxx ?>
    <?php foreach ($proofRows as $i => $r): ?>
    <div class="split proofrow">
      <div>
        <input type="hidden" name="proof[<?= $i ?>][id]" value="<?= pm_h((string)($r['id'] ?? '')) ?>"><input type="hidden" name="proof[<?= $i ?>][lead_id]" value="<?= pm_h((string)($r['lead_id'] ?? '')) ?>">
        <select name="proof[<?= $i ?>][type]" aria-label="Type"><?php foreach (['quote' => 'Quote', 'result' => 'Result', 'photo' => 'Photo or story', 'offer' => 'Offer', 'stay' => 'Stay (host we may feature)'] as $k => $l): ?><option value="<?= $k ?>" <?= ($r['type'] ?? 'quote') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
        <textarea name="proof[<?= $i ?>][text]" rows="2" placeholder="Exact words (quote, result or offer)" aria-label="Text"><?= pm_h((string)($r['text'] ?? '')) ?></textarea>
      </div>
      <div>
        <input type="text" name="proof[<?= $i ?>][client_name]" value="<?= pm_h((string)($r['client_name'] ?? '')) ?>" placeholder="Client name" aria-label="Client name">
        <input type="text" name="proof[<?= $i ?>][consent_note]" value="<?= pm_h((string)($r['consent_note'] ?? '')) ?>" placeholder="How consent was given" aria-label="Consent note">
        <input type="date" name="proof[<?= $i ?>][expires]" value="<?= pm_h((string)($r['expires'] ?? '')) ?>" aria-label="Ends on">
        <input type="text" name="proof[<?= $i ?>][fb_url]" value="<?= pm_h((string)($r['fb_url'] ?? '')) ?>" placeholder="Their Facebook page link" aria-label="Facebook page link">
        <input type="text" name="proof[<?= $i ?>][ig_handle]" value="<?= pm_h((string)($r['ig_handle'] ?? '')) ?>" placeholder="Instagram handle (no @)" aria-label="Instagram handle">
        <label class="check"><input type="checkbox" name="proof[<?= $i ?>][tag_ok]" value="1" <?= !empty($r['tag_ok']) ? 'checked' : '' ?>> They are happy to be tagged</label>
        <input type="hidden" name="proof[<?= $i ?>][host_id]" value="<?= pm_h((string)($r['host_id'] ?? '')) ?>">
        <?php if (!empty($r['consent_at'])): ?><span class="hint">Consent recorded <?= pm_h((string)$r['consent_at']) ?></span><?php endif; ?>
        <label class="check"><input type="checkbox" name="proof[<?= $i ?>][consent]" value="1" <?= !empty($r['consent']) ? 'checked' : '' ?>> They agreed to be featured</label>
        <?php if (!empty($r['id'])): ?><label class="check"><input type="checkbox" name="proof[<?= $i ?>][delete]" value="1"> Delete</label><?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <div class="btns"><button class="btn small primary" name="do" value="proof_save">Save the proof bank</button></div>
  </form>
</div>

<div class="card" id="tags">
  <h2>Hashtags</h2>
  <p class="hint mt-0">The planner picks a few of these for each post and rotates them. Leave a box empty to use the built-in set. Separate them with spaces or commas.</p>
  <form method="post"><?= $sx('tags_save') ?>
    <div class="row">
      <div><label>Local (place and community)</label><textarea name="local" rows="2" placeholder="#Malawi #Lilongwe"><?= pm_h(implode(' ', (array)$bank['tags']['local'])) ?></textarea></div>
      <div><label>Niche (what we do)</label><textarea name="niche" rows="2" placeholder="#SmallBusiness"><?= pm_h(implode(' ', (array)$bank['tags']['niche'])) ?></textarea></div>
    </div>
    <div class="btns"><button class="btn small primary">Save the hashtags</button></div>
  </form>
</div>

<?php if ($rec): ?><div class="card" id="recycle">
  <h2>Bring back what worked</h2>
<?php if (!$rec): ?><p class="hint m-0">No post is ready to come back yet. A post can return when it is among the best 20% by 7-day engagement, older than 45 days, not about a date or offer, and not used again in the last 90 days. At least 5 such posts are needed before one is picked.</p>
<?php else: ?>
  <div class="rs-wrap"><table class="grid compact"><tr><th>Post</th><th class="n">Engagement</th><th class="n">Age</th><th></th></tr>
  <?php foreach ($rec as $c): ?><tr><td><?= pm_h((string)(($c['post']['headline'] ?? '') ?: mb_substr((string)$c['post']['caption'], 0, 60))) ?><br><span class="hint"><?= pm_h((string)($c['post']['pillar'] ?? '')) ?></span></td><td class="n"><?= $num($c['eng']) ?></td><td class="n"><?= (int)$c['age_days'] ?> days</td>
    <td><form method="post"><?= $sx('recycle', '<input type="hidden" name="id" value="' . pm_h($c['post']['id']) . '">') ?><button class="btn small">Recycle</button></form></td></tr><?php endforeach; ?>
  </table></div>
  <p class="hint">A recycled post becomes a new draft with a fresh opening line and hashtags. It goes through the same checks and waits for your approval (unless Auto-publish is on).</p>
<?php endif; ?>
</div><?php endif; ?>
