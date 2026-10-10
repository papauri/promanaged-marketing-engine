<?php
/**
 * Social > Lead posts: turn an offer into bold, ready-to-approve posts with a button, a comment keyword and a landing page that collects client details.
 * Free and unlimited because it is organic; Facebook's paid reach is the Ads screen. Shares index.php's variables ($vb, $settings, $csrf).
 */
pm_brand_set($vb);
$bname = pm_brand_title($settings, $vb);
$offers = array_reverse(pm_lp_for_brand($vb));
$def = pm_lp_defaults($vb);
$tpls = pm_lp_templates();
$hosted = pm_app_url() !== '';
$hasWa = pm_lp_wa_number($vb) !== '';
$sx = fn($do, $extra = '') => '<input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="social_ext"><input type="hidden" name="do" value="' . $do . '">' . $extra;
$hid = fn($n, $v) => '<input type="hidden" name="' . $n . '" value="' . pm_h((string)$v) . '">';

/** The offer fields, for a new offer and for editing one. $o is the offer or the defaults. */
$fields = function (array $o) use ($def): string {
    $v = fn(string $k) => pm_h(is_array($o[$k] ?? '') ? implode("\n", (array)$o[$k]) : (string)($o[$k] ?? ''));
    $h = '<div class="row"><div><label>What are you offering? *</label><input type="text" name="title" maxlength="70" required value="' . $v('title') . '" placeholder="e.g. A free site visit"></div>'
        . '<div><label>Comment keyword</label><input type="text" name="keyword" maxlength="10" value="' . $v('keyword') . '" placeholder="QUOTE"></div></div>'
        . '<label>The problem you fix, in your customer\'s words</label><input type="text" name="problem" maxlength="140" value="' . $v('problem') . '" placeholder="e.g. Rooms sold twice">'
        . '<label>What you do about it</label><input type="text" name="fix" maxlength="160" value="' . $v('fix') . '" placeholder="e.g. We put every booking in one calendar.">'
        . '<label>Something that really happened (optional, for the "something that happened" post)</label><input type="text" name="proof" maxlength="160" value="' . $v('proof') . '" placeholder="Only what is true">'
        . '<label>Signs your customer needs it (optional, one per line, up to four)</label><textarea name="signs" rows="3" placeholder="You lose track of who has paid&#10;You answer the same questions all day">' . $v('signs') . '</textarea>'
        . '<div class="row"><div><label>Button</label><select name="button">';
    foreach (PM_LP_BUTTONS as $k => $l) {
        $h .= '<option value="' . $k . '"' . (($o['button'] ?? 'whatsapp') === $k ? ' selected' : '') . '>' . pm_h($l) . '</option>';
    }
    $h .= '</select></div><div><label>Ends on (optional)</label><input type="date" name="ends" value="' . $v('ends') . '"></div></div>'
        . '<label>Reply to people who comment the keyword (optional; a plain default is used if empty)</label><input type="text" name="reply" maxlength="240" value="' . $v('reply') . '" placeholder="Thanks for sending it. Tell us a little about what you need and a person will reply.">'
        . '<label>Questions on the landing page form (up to three)</label>';
    $qs = array_pad((array)($o['questions'] ?? $def['questions']), 3, ['label' => '', 'options' => []]);
    foreach (array_slice($qs, 0, 3) as $i => $q) {
        $h .= '<div class="row"><div><input type="text" name="questions[' . $i . '][label]" maxlength="80" value="' . pm_h((string)$q['label']) . '" placeholder="Question ' . ($i + 1) . '"></div>'
            . '<div><input type="text" name="questions[' . $i . '][options]" maxlength="200" value="' . pm_h(implode(', ', (array)$q['options'])) . '" placeholder="Answers to pick from, comma separated (optional)"></div></div>';
    }
    return $h;
};
?>
<?= pm_social_nav('leadposts') ?>
<?php if (!$hasWa || !$hosted): ?>
<div class="card nofold"><b>Before the buttons work well:</b>
  <ul class="hint" style="margin:6px 0 0 18px">
    <?php if (!$hasWa): ?><li>Set a mobile <b>WhatsApp number</b> in <a href="?tab=settings&amp;brand=<?= pm_h($vb) ?>">Settings</a>. The tap-to-chat button and the picture need it.</li><?php endif; ?>
    <?php if (!$hosted): ?><li>The landing page and its form need the app online (<code>APP_URL</code> in .env). Until then every post's button opens WhatsApp instead, which works anywhere.</li><?php endif; ?>
  </ul></div>
<?php endif; ?>

<div class="card nofold">
  <b>Free, unlimited, and honest.</b> Pick an offer, choose the templates and the volume, and get ready-to-approve posts with a picture that carries a real button, a comment keyword that turns commenters into leads,
  and a landing page that collects their details. Nothing here costs money, and nothing is published until you approve it in Plan.
  <p class="hint" style="margin:6px 0 0">Posts go out one a day by the normal schedule. Facebook does not let a normal post carry a clickable button, so the button is in the picture, on the landing page, and as the tap-to-chat link; set the Page's own action button to "Send WhatsApp message" too (Channels).
    Want paid reach? The <a href="?tab=social&amp;view=ads">Ads</a> screen plans a paused campaign you can switch on.</p>
</div>

<?php foreach ($offers as $o):
    $st = pm_lp_stats($o);
    $live = ($o['status'] ?? '') === 'active' && (($o['ends'] ?? '') === '' || $o['ends'] >= date('Y-m-d'));
    $page = pm_lp_url($vb, (string)$o['id'], 'link');
    ?>
<details class="card" <?= $live ? 'open' : '' ?> id="o<?= pm_h((string)$o['id']) ?>">
  <summary><b><?= pm_h($o['title']) ?></b>
    <span class="pill <?= $live ? 'hot' : 'warn' ?>"><?= $live ? 'live' : (($o['status'] ?? '') === 'paused' ? 'paused' : 'ended') ?></span>
    <span class="pill"><?= pm_h(PM_LP_VOLUMES[$o['volume']] ?? 'Bold') ?></span>
    <span class="muted" style="font-size:12px"><?= (int)$st['posts'] ?> posts · <?= (int)$st['leads'] ?> leads<?= $o['keyword'] !== '' ? ' · ' . pm_h($o['keyword']) : '' ?></span></summary>

  <div class="kpis" style="margin-top:0">
    <div><b><?= (int)$st['posts'] ?></b><span>posts made · <?= (int)$st['published'] ?> published · <?= (int)$st['drafts'] ?> waiting</span></div>
    <div><b><?= (int)$st['views'] ?></b><span>landing page views</span></div>
    <div><b><?= (int)$st['leads'] ?></b><span>leads from it</span></div>
    <div><b><?= (int)$st['keyword_comments'] ?></b><span>commented <?= pm_h($o['keyword']) ?></span></div>
  </div>
  <?php if ($page !== ''): ?><p class="hint">Landing page: <a href="<?= pm_h($page) ?>" target="_blank" rel="noopener noreferrer"><?= pm_h($page) ?></a> <button type="button" class="btn small" data-copy="<?= pm_h($page) ?>">Copy link</button></p>
  <?php elseif ($hasWa): ?><p class="hint">Tap-to-chat link: <button type="button" class="btn small" data-copy="<?= pm_h(pm_lp_wa_link($o)) ?>">Copy WhatsApp link</button> (works anywhere; the landing page needs the app online).</p><?php endif; ?>

  <form method="post"><?= $sx('lp_post', $hid('id', $o['id'])) ?>
    <h3>Pick the templates</h3>
    <div class="lpgrid">
    <?php foreach ($tpls as $tid => $t): [$can, $why] = pm_lp_can_use($o, $tid); $cp = $can ? pm_lp_copy($o, $tid, $o['volume']) : null; ?>
      <label class="lptpl <?= $can ? '' : 'off' ?>"><span class="lphead"><input type="checkbox" name="templates[]" value="<?= $tid ?>" <?= $can ? (in_array($tid, ['callout', 'freebie', 'question', 'quote'], true) ? 'checked' : '') : 'disabled' ?>> <b><?= pm_h($t['label']) ?></b></span>
        <span class="muted"><?= pm_h($t['goal']) ?></span>
        <?php if ($cp): ?><span class="lpshot"><b><?= pm_h($cp['headline']) ?></b><?php foreach (array_slice($cp['bullets'], 0, 2) as $b): ?><i><?= pm_h($b) ?></i><?php endforeach; ?><em><?= pm_h(PM_LP_BUTTONS[$o['button']] ?? '') ?> ›</em></span>
          <details onclick="event.stopPropagation()"><summary>Read the post</summary><span class="lpcap"><?= nl2br(pm_h($cp['caption'])) ?></span></details>
        <?php else: ?><span class="hint"><?= pm_h($why) ?></span><?php endif; ?></label>
    <?php endforeach; ?>
    </div>
    <h3>Volume</h3>
    <div class="checks">
      <?php foreach (PM_LP_VOLUMES as $k => $l): ?><label class="check"><input type="radio" name="volume" value="<?= $k ?>" <?= $o['volume'] === $k ? 'checked' : '' ?>> <b><?= pm_h($l) ?></b> <span class="muted"><?= pm_h(['calm' => 'plain and friendly', 'bold' => 'short, direct, confident', 'unhinged' => 'loud, pattern-breaking, a bit cheeky'][$k]) ?></span></label><?php endforeach; ?>
    </div>
    <p class="hint">Volume changes the energy only. Every post is checked like any other: no invented numbers, prices, claims, guarantees or fake deadlines, and only your own words as facts.</p>
    <div class="btns"><button class="btn primary">Make draft posts</button></div>
  </form>

  <details class="more"><summary>Edit this offer</summary>
    <form method="post"><?= $sx('lp_save', $hid('id', $o['id'])) ?><?= $fields($o) ?><div class="btns"><button class="btn small primary">Save changes</button></div></form>
  </details>
  <div class="btns">
    <form method="post"><?= $sx('lp_status', $hid('id', $o['id']) . $hid('to', ($o['status'] ?? '') === 'active' ? 'paused' : 'active')) ?><button class="btn small"><?= ($o['status'] ?? '') === 'active' ? 'Pause' : 'Make it live again' ?></button></form>
    <form method="post" onsubmit="return confirm('Delete this offer? Posts already made keep their text.')"><?= $sx('lp_delete', $hid('id', $o['id'])) ?><button class="btn small danger">Delete</button></form>
  </div>
</details>
<?php endforeach; ?>

<?php $ideas = pm_lp_ideas($vb); ?>
<div class="card nofold" id="ideas">
  <h2>Not sure what to offer?</h2>
  <p class="hint" style="margin-top:0">Get three ideas from what this business told the app: its facts, what it offers, its free first step and who it wants to reach. <?= pm_agents_ready() ? 'The AI writes them, using only those facts.' : 'The AI is not set up, so you get the free first step as a starting point.' ?></p>
  <form method="post" class="btns"><?= $sx('lp_suggest') ?><button class="btn small primary"><?= $ideas['rows'] ? 'Suggest different ideas' : 'Suggest offers' ?></button>
    <?php if ($ideas['rows']): ?><button class="btn small" name="do" value="lp_ideas_clear">Clear the ideas</button><?php endif; ?></form>
  <?php foreach ($ideas['rows'] as $i => $idea): ?>
  <details class="card" style="margin:8px 0" <?= $i === 0 ? 'open' : '' ?>><summary><b><?= pm_h($idea['title']) ?></b> <span class="muted" style="font-size:12px"><?= $idea['keyword'] !== '' ? '· ' . pm_h($idea['keyword']) . ' ' : '' ?>· an idea, not saved yet</span></summary>
    <form method="post"><?= $sx('lp_save') ?><?= $hid('make', '1') ?>
      <?php foreach (['freebie', 'callout', 'question', 'quote', 'whatsapp', 'ask'] as $dt): ?><?= $hid('templates[]', $dt) ?><?php endforeach; ?>
      <?= $fields($idea) ?>
      <div class="btns"><button class="btn primary">Use this and make my first posts</button><button class="btn" name="make" value="">Just save it</button></div>
    </form>
  </details>
  <?php endforeach; ?>
</div>

<div class="card" <?= $offers ? 'data-fold="closed"' : '' ?> id="new">
  <h2><?= $offers ? 'Another offer' : 'Start with your first offer' ?> · <?= pm_h($bname) ?></h2>
  <form method="post"><?= $sx('lp_save') ?><?= $hid('make', '1') ?>
    <?php foreach (['freebie', 'callout', 'question', 'quote', 'whatsapp', 'ask'] as $dt): ?><?= $hid('templates[]', $dt) ?><?php endforeach; ?>
    <?= $fields($def) ?>
    <div class="btns"><button class="btn primary">Save and make my first posts</button><button class="btn" name="make" value="">Just save it</button></div>
    <p class="hint">"Save and make my first posts" drafts every template that fits what you filled in, at the Bold volume. You can change the volume and make more any time.</p>
  </form>
</div>
