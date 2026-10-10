<?php
/**
 * Adding a business. Three short steps: tell us about it, check what we drafted, done (with what to set up next).
 * Also draws the grid of businesses (used here and at the top of Settings).
 * Shares index.php's variables ($csrf, $settings, $vb). The handlers are in index.php: brand_draft, brand_create, brand_cancel.
 */

$wz = (array)($_SESSION['bwiz'] ?? []);
$step = isset($_GET['new']) ? 1 : (isset($_GET['step']) && $wz ? 2 : 0);
if (isset($_GET['id']) && pm_brand_is_custom((string)$_GET['id']) && isset($_GET['welcome'])) {
    $step = 3;
}
$stepper = function (int $on) {
    $items = [1 => 'About the business', 2 => 'Check the draft', 3 => 'Ready'];
    $h = '<ol class="stepper">';
    foreach ($items as $i => $l) {
        $h .= '<li class="' . ($i === $on ? 'on' : ($i < $on ? 'done' : '')) . '"><b>' . ($i < $on ? '✓' : $i) . '</b>' . pm_h($l) . '</li>';
    }
    return $h . '</ol>';
};
$ans = (array)($wz['answers'] ?? []);
$av = fn(string $k) => pm_h((string)($ans[$k] ?? ''));

if ($step === 0) {
    echo pm_ui_head('Your businesses', 'Each business has its own voice, mailbox, leads, posts and limits. They never borrow each other\'s words.', '<a class="btn primary" href="?tab=business&amp;new=1">+ Add a business</a>');
    pm_view_biz_grid($settings, $vb, $csrf);
    return;
}

if ($step === 1) {
    echo pm_ui_head('Add a business', 'Three questions are enough to start. Everything else is optional, and you can change every answer later.');
    echo $stepper(1);
    ?>
<form method="post" class="card wiz nofold"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="brand_draft">
  <label>What is the business called? *</label>
  <input type="text" name="name" required maxlength="60" value="<?= $av('name') ?>" placeholder="e.g. Sunrise Solar" autofocus>
  <label>What does it do or sell? *</label>
  <textarea name="sells" required rows="3" placeholder="One or two plain sentences, e.g. We design, install and maintain solar power systems for homes and small businesses."><?= $av('sells') ?></textarea>
  <label>Who buys from you? *</label>
  <input type="text" name="customers" required maxlength="200" value="<?= $av('customers') ?>" placeholder="e.g. Schools, clinics and small businesses that want reliable power">
  <label>Who do you mainly sell to?</label>
  <div class="checks" style="margin-top:2px">
    <?php foreach (['business' => 'Other businesses (the agents find and write to them)', 'public' => 'The public (social media, enquiries and partners; no cold emails)', 'both' => 'Both'] as $k => $l): ?>
      <label class="check"><input type="radio" name="sell_to" value="<?= $k ?>" <?= ($ans['sell_to'] ?? 'business') === $k ? 'checked' : '' ?>> <?= pm_h($l) ?></label>
    <?php endforeach; ?>
  </div>
  <details class="more" style="margin-top:12px" <?= array_filter([$ans['cities'] ?? '', $ans['targets'] ?? '', $ans['facts'] ?? '', $ans['email'] ?? '']) ? 'open' : '' ?>><summary>More, if you like (the agents work better with it)</summary>
    <div class="two">
      <div><label>Cities or towns to search</label><input type="text" name="cities" value="<?= $av('cities') ?>" placeholder="Lilongwe, Blantyre, Mzuzu"></div>
      <div><label>Kinds of business or people to look for</label><input type="text" name="targets" value="<?= $av('targets') ?>" placeholder="schools, clinics, guest houses"></div>
    </div>
    <label>True things we may say about you (one per line)</label>
    <textarea name="facts" rows="3" placeholder="We install and maintain solar systems&#10;We offer a free site visit"><?= $av('facts') ?></textarea>
    <p class="hint">The AI says nothing about you beyond these lines and your answers above. It never invents numbers, awards, prices or customers.</p>
    <div class="two">
      <div><label>A free first step you offer</label><input type="text" name="magnet" value="<?= $av('magnet') ?>" placeholder="e.g. a free site visit"></div>
      <div><label>How should it sound?</label><select name="voice"><?php foreach (['friendly' => 'Friendly and practical', 'professional' => 'Professional and clear', 'warm' => 'Warm and local', 'bold' => 'Confident and direct'] as $k => $l): ?><option value="<?= $k ?>" <?= ($ans['voice'] ?? 'friendly') === $k ? 'selected' : '' ?>><?= pm_h($l) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="two">
      <div><label>Business email</label><input type="email" name="email" value="<?= $av('email') ?>" placeholder="info@example.com"></div>
      <div><label>Phone or WhatsApp</label><input type="text" name="phone" value="<?= $av('phone') ?>" placeholder="0999 123 456"></div>
    </div>
    <div class="two">
      <div><label>Website</label><input type="text" name="website" value="<?= $av('website') ?>" placeholder="www.example.com"></div>
      <div><label>Never say (optional)</label><input type="text" name="never" value="<?= $av('never') ?>" placeholder="e.g. Never promise delivery dates"></div>
    </div>
  </details>
  <div class="btns"><button class="btn primary">Draft my profile</button><a class="btn ghost" href="?tab=settings">Cancel</a></div>
  <p class="hint">One small AI call turns your answers into a draft you can edit. No AI key yet? You get a plain draft from your own words.</p>
</form>
    <?php
    return;
}

if ($step === 2) {
    $d = (array)($wz['draft'] ?? []);
    $lines = fn($v) => pm_h(implode("\n", (array)$v));
    echo pm_ui_head('Check the draft for ' . pm_h((string)($ans['name'] ?? '')), 'This is what the agents and the post writer will know. Fix anything that is not true or not how you would say it.');
    echo $stepper(2);
    if (!empty($wz['note'])) {
        echo '<div class="flash ' . (!empty($wz['ai']) ? 'ok' : 'err') . '">' . pm_h((string)$wz['note']) . '</div>';
    } elseif (!empty($wz['ai'])) {
        echo '<div class="flash ok">Drafted with the AI from your answers. Facts with numbers you did not write were left out.</div>';
    }
    ?>
<form method="post" class="card wiz nofold"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="brand_create">
  <label>About the business</label>
  <textarea name="d[about]" rows="2"><?= pm_h((string)($d['about'] ?? '')) ?></textarea>
  <label>What it offers (one per line, "Name: what it is")</label>
  <textarea name="d[offerings]" rows="3"><?= $lines($d['offerings'] ?? []) ?></textarea>
  <label>True things the AI may say (one per line)</label>
  <textarea name="d[facts]" rows="4"><?= $lines($d['facts'] ?? []) ?></textarea>
  <div class="two">
    <div><label>Who buys</label><input type="text" name="d[audience]" value="<?= pm_h((string)($d['audience'] ?? '')) ?>"></div>
    <div><label>Voice</label><input type="text" name="d[voice]" value="<?= pm_h((string)($d['voice'] ?? '')) ?>"></div>
  </div>
  <label>Never</label>
  <input type="text" name="d[never]" value="<?= pm_h((string)($d['never'] ?? '')) ?>">
  <div class="two">
    <div><label>Kinds of business or people to look for (comma separated)</label><input type="text" name="d[sectors]" value="<?= pm_h(implode(', ', (array)($d['sectors'] ?? []))) ?>"></div>
    <div><label>Cities to search (comma separated)</label><input type="text" name="d[cities]" value="<?= pm_h(implode(', ', (array)($d['cities'] ?? []))) ?>"></div>
  </div>
  <div class="two">
    <div><label>Free first step you offer</label><input type="text" name="d[magnet]" value="<?= pm_h((string)($d['magnet'] ?? '')) ?>" placeholder="leave empty if there is none"></div>
    <div><label>WhatsApp keyword for it</label><input type="text" name="d[cta_keyword]" maxlength="10" value="<?= pm_h((string)($d['cta_keyword'] ?? '')) ?>" placeholder="QUOTE"></div>
  </div>
  <label>Topics for social posts (one per line, "Name: share of posts")</label>
  <textarea name="d[pillars]" rows="4"><?= pm_h(implode("\n", array_map(fn($p) => $p['name'] . ': ' . $p['weight'], (array)($d['pillars'] ?? [])))) ?></textarea>
  <div class="btns"><button class="btn primary">Create <?= pm_h((string)($ans['name'] ?? 'the business')) ?></button><a class="btn" href="?tab=business&amp;new=1">Back</a>
    <button class="btn ghost" name="action" value="brand_cancel" formnovalidate>Start over</button></div>
  <p class="hint">Creating a business sends nothing and switches nothing on. Email, Facebook and WhatsApp stay off until you connect them.</p>
</form>
    <?php
    return;
}

// step 3: created
$id = (string)$_GET['id'];
$title = pm_brand_title($settings, $id);
echo pm_ui_head($title . ' is ready', 'It is set up with its own voice and limits. A few things are left before it can work end to end.');
echo $stepper(3);
$rows = pm_brand_checklist($id);
?>
<div class="card nofold"><h2>What is left</h2>
  <ul class="nextlist">
  <?php foreach ($rows as $r): ?><li class="<?= $r['ok'] ? 'ok' : '' ?>"><span class="mark"><?= $r['ok'] ? '✓' : '' ?></span><div><b><?= pm_h($r['label']) ?></b><br><span class="muted"><?= $r['ok'] ? 'Done.' : pm_h($r['hint']) ?></span></div></li><?php endforeach; ?>
  <li><span class="mark"></span><div><b>Connect Facebook (optional)</b><br><span class="muted">Posts need a Facebook Page. Until then the planner still drafts posts and you can hand-post them.</span> <a href="?tab=social&amp;view=accounts&amp;brand=<?= pm_h($id) ?>">Connect it in 3 steps</a></div></li>
  <li><span class="mark"></span><div><b>Review what the AI knows</b><br><span class="muted">Its voice, facts and what it looks for are in the business settings.</span> <a href="?tab=settings&amp;brand=<?= pm_h($id) ?>">Open settings</a></div></li>
  </ul>
  <div class="btns">
    <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="agents"><input type="hidden" name="do" value="run"><button class="btn primary" <?= pm_agents_ready() ? '' : 'disabled title="Add an AI key to .env first"' ?>>Run the agents now</button></form>
    <a class="btn" href="?tab=settings&amp;brand=<?= pm_h($id) ?>">Business settings</a>
    <a class="btn" href="?tab=agents&amp;brand=<?= pm_h($id) ?>">Open leads</a>
  </div>
  <p class="hint">The agents start by themselves each weekday morning once the scheduler is running (no change to the scheduled task is needed).</p>
</div>
