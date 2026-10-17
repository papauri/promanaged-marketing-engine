<?php
/**
 * Social > One-page ad: one picture that explains the business to one audience, step by step (lib/sx_explainer.php). The heads meet and decide it, the owner
 * fine-tunes it by chatting with the head of Social or editing the words, and approves it. Shares index.php's variables ($vb, $csrf).
 */
pm_brand_set($vb);
$fx = fn(string $do, string $extra = '') => '<input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="social_ext"><input type="hidden" name="do" value="' . $do . '">' . $extra;
$ads = pm_ex_all($vb);
$xid = preg_replace('/[^a-f0-9]/', '', (string)($_GET['x'] ?? ''));
$x = $xid !== '' ? pm_ex_get($vb, $xid) : ($ads[0] ?? null);
$auds = pm_ex_audiences($vb);
$offs = pm_ex_offerings($vb);
$head = pm_agent_first('social');
$picsOk = function_exists('pm_aiimg_ready') && pm_aiimg_ready($vb);
$picSel = fn(string $on) => '<select name="pics">' . implode('', array_map(fn($k, $v) => '<option value="' . $k . '"' . ($k === $on ? ' selected' : '') . '>' . pm_h($v) . '</option>', array_keys(PM_EX_PICS), PM_EX_PICS)) . '</select>';
echo pm_social_nav('explainer');
?>
<div class="card">
  <h2 class="mt-0">Make a one-page ad</h2>
  <p class="hint mt-0">One picture that explains <?= pm_h(pm_brand_name($vb)) ?> to one audience, step by step: what we offer, how easy it is to start, what they get and how to reach us. The department heads meet and decide it together, you fine-tune it with <?= pm_h($head) ?> (head of Social), and nothing goes out until you approve it.</p>
  <form method="post"><?= $fx('ex_meet') ?>
    <div class="row">
      <div><label>Who is it for?</label><input type="text" name="audience" list="exauds" value="<?= pm_h($auds[0] ?? '') ?>" maxlength="120"><datalist id="exauds"><?php foreach ($auds as $a): ?><option value="<?= pm_h($a) ?>"><?php endforeach; ?></datalist></div>
      <?php if ($picsOk): ?><div><label>Pictures</label><?= $picSel('hero') ?></div><?php endif; ?>
      <div><label>What should it sell?</label><select name="focus"><option value="">Everything we offer</option><?php foreach ($offs as $k => $full): ?><option value="<?= pm_h($k) ?>" title="<?= pm_h($full) ?>"><?= pm_h($k) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="btns"><button class="btn primary"><?= $ads ? 'Call the team together for a new ad' : 'Call the team together' ?></button>
      <span class="hint"><?= pm_agents_ready() ? 'One small AI call for the words; the ad is drawn by the app for free, and AI illustrations cost what the Pictures choice says.' : 'No AI key yet: the ad is built from your own words.' ?></span></div>
  </form>
  <?php if (count($ads) > 1): ?><nav class="filters mt-2" aria-label="Ads"><?php foreach ($ads as $a): ?><a href="?tab=social&amp;view=explainer&amp;x=<?= pm_h($a['id']) ?>"<?= $x && $a['id'] === $x['id'] ? ' class="on" aria-current="true"' : '' ?>><?= pm_h(mb_strimwidth((string)($a['audience'] ?: 'Ad'), 0, 34, '…')) ?> <span class="muted t-sm"><?= ($a['status'] ?? '') === 'approved' ? '· approved' : '· ' . pm_h(date('j M', strtotime((string)$a['at']))) ?></span></a><?php endforeach; ?></nav><?php endif; ?>
</div>

<?php if ($x):
    $files = pm_ex_files($x['id']);
    $v = substr(md5((string)@filemtime($files['share'] ?? '')), 0, 8);
    $src = fn(string $f, bool $dl = false) => '?explainer=' . pm_h($x['id']) . '&amp;b=' . pm_h($vb) . '&amp;f=' . $f . '&amp;v=' . $v . ($dl ? '&amp;dl=1' : '');
    $ad = (array)$x['ad'];
    $approved = ($x['status'] ?? '') === 'approved';
    $names = function_exists('pm_agent_names') ? pm_agent_names() : [];
?>
<div class="exgrid">
  <div class="card expic">
    <?php if (!empty($files['share'])): ?><a href="<?= $src('share') ?>" target="_blank" rel="noopener"><img src="<?= $src('share') ?>" alt="<?= pm_h($ad['headline']) ?>" width="1080" height="1350"></a>
    <?php else: ?><p class="flash err">The picture could not be drawn. Check Operations > Connections > Pictures.</p><?php endif; ?>
    <div class="btns">
      <?php if (!$approved): ?><form method="post" class="inl"><?= $fx('ex_approve', '<input type="hidden" name="x" value="' . pm_h($x['id']) . '">') ?><button class="btn primary">Approve and put it in the plan</button></form>
      <?php else: ?><span class="chip ok">Approved<?= !empty($x['approved_by']) ? ' by ' . pm_h((string)$x['approved_by']) : '' ?> · <?= pm_h(date('j M H:i', strtotime((string)$x['approved_at']))) ?></span><a class="btn small" href="?tab=social">In the plan for <?= pm_h(date('D j M H:i', strtotime((string)($x['post_when'] ?? 'now')))) ?></a><?php endif; ?>
      <?php if (!empty($files['share'])): ?><a class="btn small" href="<?= $src('share', true) ?>">Download picture</a><?php endif; ?>
      <?php if ($picsOk): ?><form method="post" class="inl expics"><?= $fx('ex_pics', '<input type="hidden" name="x" value="' . pm_h($x['id']) . '">') ?><?= $picSel((string)($x['pics_mode'] ?? 'icons')) ?><button class="btn small">Draw it with these pictures</button></form><?php endif; ?>
      <?php if (!empty($files['a4'])): ?><a class="btn small" href="<?= $src('a4', true) ?>">A4 PDF to print</a><?php endif; ?>
      <?php if (!empty($x['prev'])): ?><form method="post" class="inl"><?= $fx('ex_undo', '<input type="hidden" name="x" value="' . pm_h($x['id']) . '">') ?><button class="btn small ghost">Undo the last change</button></form><?php endif; ?>
      <form method="post" class="inl"><?= $fx('ex_delete', '<input type="hidden" name="x" value="' . pm_h($x['id']) . '">') ?><button class="btn small danger" data-confirm="Delete this ad?">Delete</button></form>
    </div>
  </div>

  <div class="stack">
    <div class="card">
      <h2 class="mt-0">How the team decided</h2>
      <p class="hint mt-0">For <?= pm_h((string)($x['audience'] ?: 'everyone')) ?><?= $x['focus'] !== '' ? ' · selling ' . pm_h((string)$x['focus']) : '' ?> · <?= pm_h(date('j M H:i', strtotime((string)$x['at']))) ?><?= ($x['why'] ?? '') !== '' ? ' · ' . pm_h((string)$x['why']) : '' ?></p>
      <ul class="talklist"><?php foreach ((array)$x['lines'] as [$w, $said]): ?><li><time></time><b><?= pm_h($names[$w] ?? ucfirst($w)) ?>:</b><span><?= pm_h($said) ?></span></li><?php endforeach; ?></ul>
    </div>

    <div class="card exchat" id="chat">
      <h2 class="mt-0">Fine-tune it with <?= pm_h($head) ?></h2>
      <?php if (!empty($x['chat'])): ?><div class="cc-log"><?php foreach ((array)$x['chat'] as $m): $me = ($m['who'] ?? '') === 'owner'; ?>
        <div class="cc-msg <?= $me ? 'me' : 'agent' ?>"><?php if (!$me): ?><b><?= pm_h($head) ?> · Social</b><?php endif; ?><span><?= pm_h((string)$m['said']) ?></span>
          <?php if (!$me && !empty($m['lesson'])): ?><form method="post" class="mt-1"><?= $fx('ex_remember', '<input type="hidden" name="x" value="' . pm_h($x['id']) . '"><input type="hidden" name="lesson" value="' . pm_h((string)$m['lesson']) . '"><input type="hidden" name="for" value="' . pm_h((string)($m['lesson_for'] ?? 'voice')) . '">') ?><button class="btn small cc-rem">Remember for everything we write: “<?= pm_h((string)$m['lesson']) ?>”</button></form><?php endif; ?></div>
      <?php endforeach; ?></div><?php else: ?><p class="hint mt-0">Tell <?= pm_h($head) ?> what to change ("shorter headline", "make step 2 about the free set-up", "use a calendar icon for the sync", "speak to lodge managers") or ask why something is there. The picture is redrawn each time.</p><?php endif; ?>
      <form method="post" class="cc-in mt-2"><?= $fx('ex_chat', '<input type="hidden" name="x" value="' . pm_h($x['id']) . '">') ?><textarea name="ask" rows="2" required placeholder="What should change?" aria-label="Message to <?= pm_h($head) ?>"></textarea><button class="btn small primary">Send</button></form>
    </div>

    <details class="card">
      <summary><b>Change the words yourself</b></summary>
      <form method="post" class="mt-2"><?= $fx('ex_save', '<input type="hidden" name="x" value="' . pm_h($x['id']) . '">') ?>
        <label>Who it is for (the small line at the top)</label><input type="text" name="eyebrow" value="<?= pm_h($ad['eyebrow']) ?>" maxlength="60">
        <label>Headline</label><input type="text" name="headline" value="<?= pm_h($ad['headline']) ?>" maxlength="90">
        <label>The promise under it (optional)</label><input type="text" name="sub" value="<?= pm_h($ad['sub']) ?>" maxlength="140">
        <label>How it works: one step a line, <span class="muted">icon | title | text</span></label><textarea name="steps" rows="4"><?= pm_h(implode("\n", array_map(fn($s) => $s['icon'] . ' | ' . $s['title'] . ' | ' . $s['text'], (array)$ad['steps']))) ?></textarea>
        <label>What you get: one a line, <span class="muted">icon | text</span></label><textarea name="gets" rows="4"><?= pm_h(implode("\n", array_map(fn($g) => $g['icon'] . ' | ' . $g['text'], (array)$ad['gets']))) ?></textarea>
        <p class="hint">Icons: <?= pm_h(implode(', ', array_keys(PM_EX_ICONS))) ?>.</p>
        <label>Our goal (optional)</label><input type="text" name="goal" value="<?= pm_h($ad['goal']) ?>" maxlength="130">
        <label>Call to action</label><input type="text" name="cta" value="<?= pm_h($ad['cta']) ?>" maxlength="70">
        <?php if (($x['pics_mode'] ?? 'icons') !== 'icons'): $pf = pm_ex_pics_fill($vb, $ad)['pics']; ?>
        <label>The picture at the top <span class="muted">(what the AI illustration shows; a new description makes a new picture, about 5 US cents)</span></label><textarea name="pic_hero" rows="2"><?= pm_h((string)$pf['hero']) ?></textarea>
        <?php if (($x['pics_mode'] ?? '') === 'all'): ?><label>A picture for each step <span class="muted">(one a line, in the order of the steps)</span></label><textarea name="pic_steps" rows="4"><?= pm_h(implode("\n", (array)$pf['steps'])) ?></textarea><?php endif; ?>
        <p class="hint">AI illustrations show generic places, objects and hands only, never your own rooms, products or people, and never any words: the app writes every word itself.</p>
        <?php endif; ?>
        <label>The post text that goes with the picture</label><textarea name="caption" rows="8" data-chat="post"><?= pm_h((string)($ad['caption'] ?? '')) ?></textarea>
        <div class="btns"><button class="btn small primary">Save and redraw</button></div>
      </form>
    </details>
  </div>
</div>
<?php endif; ?>
