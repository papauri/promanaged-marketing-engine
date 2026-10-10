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
if (isset($_GET['id']) && isset($_GET['study']) && pm_brand_is_custom((string)$_GET['id']) && (($_SESSION['bstudy']['id'] ?? '') === (string)$_GET['id'])) {
    $step = 4; // a fresh look at a business that already exists
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
  <label>Website <span class="muted">(the AI reads it, up to five pages, to learn the business and who to target)</span></label>
  <input type="text" name="website" value="<?= $av('website') ?>" placeholder="www.example.mw">
  <details class="more" style="margin-top:12px" <?= array_filter([$ans['cities'] ?? '', $ans['targets'] ?? '', $ans['facts'] ?? '', $ans['email'] ?? '', $ans['notes'] ?? '']) ? 'open' : '' ?>><summary>More, if you like (the agents work better with it)</summary>
    <label>Anything else the AI should read <span class="muted">(paste an About text, a brochure or a list of services; it is treated as your own words)</span></label>
    <textarea name="notes" rows="4" maxlength="6000" placeholder="No website? Paste a few paragraphs about the business here."><?= $av('notes') ?></textarea>
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
      <div><label>Never say (optional)</label><input type="text" name="never" value="<?= $av('never') ?>" placeholder="e.g. Never promise delivery dates"></div>
    </div>
  </details>
  <div class="btns"><button class="btn primary">Draft my profile</button><a class="btn ghost" href="?tab=settings">Cancel</a></div>
  <p class="hint">The AI reads your answers and your website (this can take a minute), then drafts what the business is, who to target and why, and the few things only you can tell it. You check everything before anything is saved. No AI key yet? You get a plain draft from your own words.</p>
</form>
    <?php
    return;
}

if ($step === 2) {
    $d = (array)($wz['draft'] ?? []);
    $lines = fn($v) => pm_h(implode("\n", (array)$v));
    $bundle = (array)($wz['pages'] ?? []);
    $segs = (array)($d['targeting']['segments'] ?? []);
    $tgt = (array)($d['targeting'] ?? []);
    $found = (array)($d['found'] ?? []);
    $srcLabel = function ($src) {
        if ($src === 'you' || $src === '') {
            return 'your words';
        }
        return preg_match('#^https?://#i', (string)$src) ? '<a href="' . pm_h((string)$src) . '" target="_blank" rel="noopener noreferrer">' . pm_h(pm_study_host((string)$src)) . '</a>' : pm_h((string)$src);
    };
    $dots = fn($n) => str_repeat('●', max(1, min(5, (int)$n))) . str_repeat('○', 5 - max(1, min(5, (int)$n)));
    echo pm_ui_head('Check the draft for ' . pm_h((string)($ans['name'] ?? '')), 'This is what the agents and the post writer will know, and who they will look for. Fix anything that is not true or not how you would say it.');
    echo $stepper(2);
    if (!empty($wz['note'])) {
        echo '<div class="flash ' . (!empty($wz['ai']) ? 'ok' : 'err') . '">' . pm_h((string)$wz['note']) . '</div>';
    }
    if (!empty($wz['ai'])) {
        echo '<div class="flash ok">Drafted with the AI from ' . (!empty($bundle['pages']) ? 'your answers and ' . count($bundle['pages']) . ' page' . (count($bundle['pages']) === 1 ? '' : 's') . ' of your website' : 'your answers') . '. Facts from the website each carry a sentence the app checked is really there; facts with numbers nobody wrote were left out.</div>';
    }
    if (!empty($bundle['pages'])) {
        echo '<p class="hint">Read: ' . implode(' · ', array_map(fn($p) => '<a href="' . pm_h((string)$p['url']) . '" target="_blank" rel="noopener noreferrer">' . pm_h(pm_study_host((string)$p['url']) . (string)parse_url((string)$p['url'], PHP_URL_PATH)) . '</a>', $bundle['pages'])) . '</p>';
    }
    ?>
<form method="post" class="wiz"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="brand_create">
  <div class="card nofold"><h2>What the business is</h2>
    <label>About the business</label>
    <textarea name="d[about]" rows="2"><?= pm_h((string)($d['about'] ?? '')) ?></textarea>
    <label>What it offers (one per line, "Name: what it is")</label>
    <textarea name="d[offerings]" rows="3"><?= $lines($d['offerings'] ?? []) ?></textarea>
    <label>True things the AI may say (one per line)</label>
    <textarea name="d[facts]" rows="5"><?= $lines($d['facts'] ?? []) ?></textarea>
    <?php if (!empty($d['sources'])): ?>
    <details class="more"><summary>Where each fact came from</summary>
      <ul class="srcs" style="margin:6px 0 0 18px"><?php foreach ((array)$d['facts'] as $fct): ?><li><?= pm_h((string)$fct) ?> <span class="muted">· <?= $srcLabel($d['sources'][$fct] ?? 'you') ?></span></li><?php endforeach; ?></ul>
    </details>
    <?php endif; ?>
    <div class="two">
      <div><label>Who buys</label><input type="text" name="d[audience]" value="<?= pm_h((string)($d['audience'] ?? '')) ?>"></div>
      <div><label>Voice</label><input type="text" name="d[voice]" value="<?= pm_h((string)($d['voice'] ?? '')) ?>"></div>
    </div>
    <label>Never</label>
    <input type="text" name="d[never]" value="<?= pm_h((string)($d['never'] ?? '')) ?>">
    <div class="two">
      <div><label>Business email<?= !empty($found['email']) ? ' <span class="muted">(from your website)</span>' : '' ?></label><input type="text" name="d[email]" value="<?= pm_h((string)($ans['email'] ?? '') !== '' ? (string)$ans['email'] : (string)($found['email'] ?? '')) ?>" placeholder="info@example.com"></div>
      <div><label>Phone or WhatsApp<?= !empty($found['phone']) ? ' <span class="muted">(from your website)</span>' : '' ?></label><input type="text" name="d[phone]" value="<?= pm_h((string)($ans['phone'] ?? '') !== '' ? (string)$ans['phone'] : (string)($found['phone'] ?? '')) ?>" placeholder="0999 123 456"></div>
    </div>
  </div>

  <div class="card nofold"><h2>Who to look for</h2>
    <div class="two">
      <div><label>Kinds of business or people to look for (comma separated)</label><input type="text" name="d[sectors]" value="<?= pm_h(implode(', ', (array)($d['sectors'] ?? []))) ?>"></div>
      <div><label>Cities to search (comma separated)</label><input type="text" name="d[cities]" value="<?= pm_h(implode(', ', (array)($d['cities'] ?? []))) ?>"></div>
    </div>
    <?php if ($segs): ?>
      <p class="hint" style="margin-bottom:4px">The AI's reasoning, from how businesses like this sell. It is advice, not a claim about you: change anything.</p>
      <ul class="why" style="margin:0 0 6px 0;padding:0;list-style:none">
      <?php foreach ($segs as $s): $inList = (bool)array_filter((array)($d['sectors'] ?? []), fn($x) => pm_study_same((string)$x, (string)$s['name'])); ?>
        <li style="margin:6px 0"><?php if (!$inList): ?><label class="check" style="display:inline"><input type="checkbox" name="d[extra][]" value="<?= pm_h((string)$s['name']) ?>"> <b><?= pm_h((string)$s['name']) ?></b></label> <span class="muted">· also worth trying</span><?php else: ?><b><?= pm_h((string)$s['name']) ?></b><?php endif; ?>
          <span class="muted" title="How well it fits what you offer">· <?= $dots($s['fit'] ?? 3) ?></span><br>
          <span class="muted"><?= pm_h((string)($s['why'] ?? '')) ?><?= !empty($s['signals']) ? ' Signs they need it: ' . pm_h(implode('; ', (array)$s['signals'])) . '.' : '' ?></span></li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?php if (!empty($tgt['skip'])): ?><p class="hint"><b>Not a fit (the agents skip these):</b> <?= pm_h(implode('; ', (array)$tgt['skip'])) ?></p><?php endif; ?>
    <?php if (!empty($tgt['angles'])): ?><p class="hint"><b>What matters to these buyers:</b> <?= pm_h(implode('; ', (array)$tgt['angles'])) ?></p><?php endif; ?>
    <?php if (!empty($tgt['cities'])): ?><p class="hint"><b>Where to start:</b> <?= pm_h(implode('; ', array_map(fn($c) => $c['name'] . (($c['why'] ?? '') !== '' ? ' (' . $c['why'] . ')' : ''), (array)$tgt['cities']))) ?></p><?php endif; ?>
    <p class="hint">You can change all of this later in the business's settings, and ask the AI to study the business again.</p>
  </div>

  <?php $qs = (array)($d['questions'] ?? []); if ($qs && (int)($wz['rounds'] ?? 0) < 3): ?>
  <div class="card nofold"><h2>What the AI would like to know</h2>
    <p class="hint" style="margin-top:0">Only you can answer these. Answer what you like, then press "Update the draft". Your answers are treated as your own words.</p>
    <?php foreach ($qs as $i => $q): ?>
      <label><?= pm_h((string)$q['q']) ?><?= ($q['why'] ?? '') !== '' ? ' <span class="muted">(' . pm_h((string)$q['why']) . ')</span>' : '' ?></label>
      <input type="hidden" name="qtext[<?= (int)$i ?>]" value="<?= pm_h((string)$q['q']) ?>"><input type="text" name="qa[<?= (int)$i ?>]" maxlength="400">
    <?php endforeach; ?>
    <div class="btns"><button class="btn" name="action" value="brand_redraft" formnovalidate>Update the draft with my answers</button></div>
  </div>
  <?php endif; ?>

  <div class="card nofold"><h2>Offers and posts</h2>
    <div class="two">
      <div><label>Free first step you offer</label><input type="text" name="d[magnet]" value="<?= pm_h((string)($d['magnet'] ?? '')) ?>" placeholder="leave empty if there is none"></div>
      <div><label>WhatsApp keyword for it</label><input type="text" name="d[cta_keyword]" maxlength="10" value="<?= pm_h((string)($d['cta_keyword'] ?? '')) ?>" placeholder="QUOTE"></div>
    </div>
    <label>Topics for social posts (one per line, "Name: share of posts")</label>
    <textarea name="d[pillars]" rows="4"><?= pm_h(implode("\n", array_map(fn($p) => $p['name'] . ': ' . $p['weight'], (array)($d['pillars'] ?? [])))) ?></textarea>
  </div>
  <div class="btns"><button class="btn primary">Create <?= pm_h((string)($ans['name'] ?? 'the business')) ?></button><a class="btn" href="?tab=business&amp;new=1">Back</a>
    <button class="btn ghost" name="action" value="brand_cancel" formnovalidate>Start over</button></div>
  <p class="hint">Creating a business sends nothing and switches nothing on. Email, Facebook and WhatsApp stay off until you connect them.</p>
</form>
    <?php
    return;
}

if ($step === 4) { // studying an existing business again: what is new, with a tick box each
    $id = (string)$_GET['id'];
    $st = (array)$_SESSION['bstudy'];
    $d = (array)$st['draft'];
    $diff = pm_study_diff($id, $d);
    $bundle = (array)($st['pages'] ?? []);
    $title = pm_brand_title($settings, $id);
    $srcLabel = fn($src) => $src === 'you' || $src === '' ? 'your words' : (preg_match('#^https?://#i', (string)$src) ? '<a href="' . pm_h((string)$src) . '" target="_blank" rel="noopener noreferrer">' . pm_h(pm_study_host((string)$src)) . '</a>' : pm_h((string)$src));
    echo pm_ui_head('What the AI learned about ' . pm_h($title), 'Tick what to add. Nothing is changed until you press "Add the ticked ones", and nothing is ever removed.');
    if (!empty($st['note'])) {
        echo '<div class="flash ' . (!empty($st['ai']) ? 'ok' : 'err') . '">' . pm_h((string)$st['note']) . '</div>';
    }
    if (empty($st['ai'])) {
        echo '<div class="flash err">The AI did not answer, so there is nothing new to add. Try again in a few minutes.</div>';
    }
    $any = array_filter([$diff['facts'], $diff['segments'], $diff['skip'], $diff['angles'], $diff['cities'], $diff['found']]);
    ?>
<form method="post" class="wiz"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="brand_study_apply"><input type="hidden" name="id" value="<?= pm_h($id) ?>">
  <?php if ($diff['facts']): ?><div class="card nofold"><h2>New facts the AI may use</h2><p class="hint" style="margin-top:0">Each one carries a sentence the app found on your website or in your own words.</p>
    <?php foreach ($diff['facts'] as $k => $x): ?><label class="check"><input type="checkbox" name="pick[facts][]" value="<?= pm_h($k) ?>" checked> <?= pm_h($x['text']) ?> <span class="muted">· <?= $srcLabel($x['src']) ?></span></label><?php endforeach; ?></div><?php endif; ?>
  <?php if ($diff['segments']): ?><div class="card nofold"><h2>More kinds of business to look for</h2>
    <?php foreach ($diff['segments'] as $k => $s): ?><label class="check"><input type="checkbox" name="pick[segments][]" value="<?= pm_h($k) ?>" <?= (int)($s['fit'] ?? 3) >= 4 ? 'checked' : '' ?>> <b><?= pm_h((string)$s['name']) ?></b> <span class="muted">· <?= pm_h((string)($s['why'] ?? '')) ?></span></label><?php endforeach; ?></div><?php endif; ?>
  <?php if ($diff['skip'] || $diff['angles']): ?><div class="card nofold"><h2>Who not to pitch, and what to lead with</h2>
    <?php foreach ($diff['skip'] as $k => $x): ?><label class="check"><input type="checkbox" name="pick[skip][]" value="<?= pm_h($k) ?>" checked> Not a fit: <?= pm_h($x) ?></label><?php endforeach; ?>
    <?php foreach ($diff['angles'] as $k => $x): ?><label class="check"><input type="checkbox" name="pick[angles][]" value="<?= pm_h($k) ?>" checked> Lead with: <?= pm_h($x) ?></label><?php endforeach; ?></div><?php endif; ?>
  <?php if ($diff['cities']): ?><div class="card nofold"><h2>Towns to search</h2>
    <?php foreach ($diff['cities'] as $k => $c): ?><label class="check"><input type="checkbox" name="pick[cities][]" value="<?= pm_h($k) ?>"> <b><?= pm_h((string)$c['name']) ?></b> <span class="muted">· <?= pm_h((string)($c['why'] ?? '')) ?></span></label><?php endforeach; ?></div><?php endif; ?>
  <?php if ($diff['found']): ?><div class="card nofold"><h2>Details your website shows</h2>
    <?php foreach ($diff['found'] as $k => $v): ?><label class="check"><input type="checkbox" name="pick[found][<?= pm_h($k) ?>]" value="1" checked> Use <?= pm_h($k === 'email' ? 'the email address' : 'the phone number') ?>: <b><?= pm_h($v) ?></b></label><?php endforeach; ?></div><?php endif; ?>
  <?php if (!$any && !empty($st['ai'])): ?><div class="card nofold"><h2>Nothing new</h2><p class="hint" style="margin-top:0">What the AI learned is already in the business's settings.</p></div><?php endif; ?>
  <?php if (!empty($diff['questions'])): ?><div class="card nofold"><h2>Things only you can tell it</h2><p class="hint" style="margin-top:0">Add the true ones to "True facts the AI may use" in the business's settings.</p>
    <ul style="margin:0 0 0 18px"><?php foreach ($diff['questions'] as $q): ?><li><?= pm_h((string)$q['q']) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <?php if (!empty($bundle['pages'])): ?><p class="hint">Read: <?= implode(' · ', array_map(fn($p) => '<a href="' . pm_h((string)$p['url']) . '" target="_blank" rel="noopener noreferrer">' . pm_h(pm_study_host((string)$p['url']) . (string)parse_url((string)$p['url'], PHP_URL_PATH)) . '</a>', $bundle['pages'])) ?></p><?php endif; ?>
  <div class="btns"><?php if ($any): ?><button class="btn primary">Add the ticked ones</button><?php endif; ?><a class="btn" href="?tab=settings&amp;brand=<?= pm_h($id) ?>">Leave it as it is</a></div>
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
  <?php $lt = pm_brand_targeting($id); $nFacts = count(array_filter(preg_split('/\R/', (string)pm_brain($id)['facts']) ?: [])); $nKinds = count((array)pm_agents_config($id)['sectors']); ?>
  <li><span class="mark"></span><div><b>Review what the AI learned</b><br><span class="muted"><?= $nFacts ?> fact<?= $nFacts === 1 ? '' : 's' ?> it may use, <?= $nKinds ?> kind<?= $nKinds === 1 ? '' : 's' ?> of business to look for<?= $lt['skip'] ? ', ' . count($lt['skip']) . ' to skip' : '' ?>. Its voice, facts and who it looks for are in the business settings; it can study the business again there.</span> <a href="?tab=settings&amp;brand=<?= pm_h($id) ?>">Open settings</a></div></li>
  <li><span class="mark"></span><div><b>Make your first offer</b><br><span class="muted">Free posts with a button, a keyword and a landing page that collect client details. The AI can suggest offers from what you told it.</span> <a href="?tab=social&amp;view=leadposts&amp;brand=<?= pm_h($id) ?>#ideas">Get offer ideas</a></div></li>
  </ul>
  <div class="btns">
    <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="agents"><input type="hidden" name="do" value="run"><button class="btn primary" <?= pm_agents_ready() ? '' : 'disabled title="Add an AI key to .env first"' ?>>Run the agents now</button></form>
    <a class="btn" href="?tab=settings&amp;brand=<?= pm_h($id) ?>">Business settings</a>
    <a class="btn" href="?tab=agents&amp;brand=<?= pm_h($id) ?>">Open leads</a>
  </div>
  <p class="hint">The agents start by themselves each weekday morning once the scheduler is running (no change to the scheduled task is needed).</p>
</div>
