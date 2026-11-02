<?php
/**
 * Sales > Targeting and messages > "First-email plan": what the whole team agreed every first email says about us (lib/opener.php). The owner has the last word: their own
 * wording replaces the team's, and the team can sit down again. Travel Malawi keeps its own fixed first email and shows nothing here.
 */
function pm_view_opener(string $vb, string $csrf): void
{
    if (!pm_op_applies($vb)) {
        return;
    }
    $p = pm_op_plan($vb);
    $team = trim((string)($p['about'] ?? ''));
    $mine = trim((string)($p['owner_about'] ?? ''));
    $shown = pm_op_about($vb);
    $bn = pm_brand_name($vb);
    $roles = ['research' => 'Research', 'replies' => 'Replies', 'writers' => 'Writers', 'sender' => 'Sender', 'followup' => 'Follow-up', 'director' => 'Director'];
    ?>
<form method="post" id="openerplan">
  <input type="hidden" name="csrf" value="<?= pm_h($csrf) ?>"><input type="hidden" name="action" value="opener"><input type="hidden" name="brand" value="<?= pm_h($vb) ?>">
  <div class="card"><h2>First-email plan · <?= pm_h($bn) ?></h2>
    <p class="hint mt-0">A new lead has never heard of us, so the first email must tell them who we are and the <b>whole</b> of what we do, not only the one thing that fits them. The team sat down and agreed the words below.
      The Writers, the study's ready-made starters and the default message (<code>{about}</code>) all use them, and any waiting first email that leaves them out has them added. The team sits down again by itself when your
      offerings or facts change, or after 30 days. <?= $p ? 'Last agreed ' . pm_h((string)($p['at'] ?? '')) . '.' : 'Not held yet: until it is, the business details are used.' ?></p>
    <label>What every first email says about us<?= $mine !== '' ? ' <span class="muted">· your wording</span>' : '' ?></label>
    <textarea name="op_about" rows="3" maxlength="500" data-grow><?= pm_h($shown) ?></textarea>
    <p class="hint">Plain words, no prices or links. It should name every part of what we offer (<?= pm_h(pm_op_offer_names($vb)) ?>) and who it is for.</p>
    <?php if (!empty($p['bridge']) || !empty($p['ask']) || !empty($p['mistakes'])): ?>
    <div class="hint">
      <?php if (!empty($p['bridge'])): ?><div><b>How to join what we noticed about them to what we do:</b> <?= pm_h((string)$p['bridge']) ?></div><?php endif; ?>
      <?php if (!empty($p['ask'])): ?><div><b>How the email ends:</b> <?= pm_h((string)$p['ask']) ?></div><?php endif; ?>
      <?php if (!empty($p['mistakes'])): ?><div><b>What the team will not repeat:</b> <?= pm_h(implode(' ', array_map('strval', (array)$p['mistakes']))) ?></div><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if (!empty($p['lines'])): ?>
    <details class="more"><summary>What the team said in the meeting</summary>
      <?php foreach ((array)$p['lines'] as [$who, $says]): ?><p class="hint"><b><?= pm_h($roles[$who] ?? ucfirst((string)$who)) ?>:</b> <?= pm_h((string)$says) ?></p><?php endforeach; ?>
    </details>
    <?php endif; ?>
  </div>
  <div class="btns formbar"><button class="btn primary" name="do" value="save">Save my wording</button>
    <button class="btn" name="do" value="hold" data-confirm="The team sits down again and writes new words (one AI call). Waiting first emails nobody edited or approved are updated to match. Go ahead?">Let the team sit down again</button>
    <?php if ($mine !== '' && $team !== ''): ?><button class="btn ghost" name="do" value="team">Back to the team's wording</button><?php endif; ?></div>
</form>
<?php
}
