<?php
/**
 * Brand > Voice: everything the AI knows about the business, and all it may claim. Every agent, email, post, reply and profile text reads this. Saved by the settings handler (the voice part
 * only), so nothing else on the business changes. Needs $vb, $settings, $csrf.
 */
pm_brand_set($vb);
$bname = pm_brand_title($settings, $vb);
$brn = pm_brain($vb);
$custom = pm_brand_is_custom($vb);
$n = fn(string $k) => $custom ? "b[brain][$k]" : ($vb === 'travel' ? "s[travel][brain][$k]" : "s[brain][$k]");
$words = preg_split('/\R/', trim((string)($brn['facts'] ?? ''))) ?: [];
$nFacts = count(array_filter(array_map('trim', $words)));
?>
<?= pm_social_nav('voice') ?>

<form method="post">
  <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="_sec" value="voice"><input type="hidden" name="back" value="social&amp;view=voice">
  <?php if ($custom): ?><input type="hidden" name="action" value="brand_save"><input type="hidden" name="id" value="<?= pm_h($vb) ?>"><?php else: ?><input type="hidden" name="action" value="settings"><?php endif; ?>
  <div class="card">
    <h2>Who we are</h2>
    <p class="hint mt-0">Everything the agents, the planner and the reply writer know about <?= pm_h($bname) ?>, and all they may claim. Change these and every email, post, reply and proposal follows. They use nothing beyond these lines and never invent numbers, prices or customers.</p>
    <label>Who we are and what we do</label><textarea name="<?= $n('about') ?>" data-sg="about" rows="3"><?= pm_h((string)$brn['about']) ?></textarea>
    <label>True facts the AI may use (one per line; nothing else is ever claimed) <span class="muted">· <?= $nFacts ?> now</span></label><textarea name="<?= $n('facts') ?>" rows="7"><?= pm_h((string)$brn['facts']) ?></textarea>
  </div>
  <div class="card">
    <h2>Who we speak to, and how</h2>
    <div class="row">
      <div><label>Who we are trying to reach</label><textarea name="<?= $n('audience') ?>" data-sg="audience" rows="2"><?= pm_h((string)$brn['audience']) ?></textarea></div>
      <div><label>Tone of voice</label><textarea name="<?= $n('voice') ?>" rows="2" placeholder="e.g. warm, plain, a little dry"><?= pm_h((string)$brn['voice']) ?></textarea></div>
      <div><label>Never say</label><textarea name="<?= $n('never') ?>" rows="2" placeholder="words or claims to keep out"><?= pm_h((string)$brn['never']) ?></textarea></div>
    </div>
  </div>
  <div class="btns formbar"><button class="btn primary">Save the voice</button><?= pm_suggest_button('voice', 'Suggest "who we are" with AI') ?></div>
</form>

<details class="card">
  <summary><b>How everything is made to sound human</b> <span class="muted">· the rules every writer follows</span></summary>
  <p class="hint"><?= pm_h(PM_HUMAN_RULES) ?></p>
  <p class="hint">After the AI writes, a free code pass also fixes dashes, stock openers, buzzwords and stacked exclamation marks. Emails, WhatsApp messages, follow-ups, replies, posts and profile words all go through it.</p>
</details>
