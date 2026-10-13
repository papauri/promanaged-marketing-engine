<?php
/**
 * Sales > Targeting and messages: how the sales team is told to work. Who to look for, the daily limits, how replies are handled and which agents are on; when approved emails go out; the default first
 * message and signatures of the business; and, for a business added in the app, who buys from it and its free first step. The AI engine and its budget are Operations > Connections; the leads are Sales > Leads.
 * Needs $vb, $settings, $csrf.
 */
pm_brand_set($vb);
$bname = pm_brand_title($settings, $vb);
$acfg = pm_agents_config($vb);
$custom = pm_brand_is_custom($vb);
$travel = $vb === 'travel';
$waBiz = pm_wa_biz_cfg()['ready'];
$agentWhat = ['scout' => 'finds businesses', 'contact' => 'finds the owner and WhatsApp', 'qualifier' => 'scores who deserves a message', 'research' => 'reads their website', 'writer' => 'drafts the first message',
    'proposals' => 'tailors the quotation', 'followup' => 'nudges quiet leads'];
$agent = fn(string $do, string $extra = '') => '<input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="agents"><input type="hidden" name="do" value="' . $do . '"><input type="hidden" name="bt" value="targeting">' . $extra;
echo pm_ui_head('Targeting and messages · ' . $bname, 'What is selling and what to do next, who to look for, the daily limits, when emails go out, and the wording of the first message. Nothing here sends anything.') . pm_ui_strip('targeting');
echo function_exists('pm_adv_card') ? pm_adv_card($vb, $csrf) : '';
?>

<form method="post">
  <?= $agent('config') ?>
  <div class="card">
    <h2>Who we look for</h2>
    <?php if (!$travel): ?><label>What we sell (one line each, "Name: description")</label>
    <textarea name="cfg[offerings]" rows="4"><?= pm_h(implode("\n", (array)$acfg['offerings'])) ?></textarea><?php endif; ?>
    <label>Types of business to look for <span class="muted">(comma separated)</span></label>
    <textarea name="cfg[sectors]" rows="2"><?= pm_h(implode(', ', (array)$acfg['sectors'])) ?></textarea>
    <label>Where we sell <span class="muted">(countries, home first; add "Worldwide" to look and write beyond them)</span></label>
    <input type="text" name="cfg[markets]" value="<?= pm_h(implode(', ', (array)($acfg['markets'] ?? ['Malawi']))) ?>" placeholder="Malawi, Zambia, Worldwide">
    <label>Cities to search <span class="muted">(comma separated; a town abroad as "Lusaka Zambia" or "Nairobi Kenya")</span></label>
    <textarea name="cfg[cities]" rows="2"><?= pm_h(implode(', ', (array)$acfg['cities'])) ?></textarea>
    <?php if ($custom): ?><label>Existing clients <span class="muted">(one per line; they are never cold-pitched)</span></label>
    <textarea name="cfg[existing_clients]" rows="3"><?= pm_h(implode("\n", (array)($acfg['existing_clients'] ?? []))) ?></textarea><?php endif; ?>
  </div>

  <div class="card">
    <h2>Limits per day</h2>
    <div class="row">
      <div><label>New leads</label><input type="number" name="cfg[new_per_day]" value="<?= (int)$acfg['new_per_day'] ?>"></div>
      <div><label>Searches</label><input type="number" name="cfg[scouts_per_day]" value="<?= (int)$acfg['scouts_per_day'] ?>"></div>
      <div><label>Emails</label><input type="number" name="cfg[send_cap]" value="<?= (int)$acfg['send_cap'] ?>"></div>
      <div><label>WhatsApp first messages</label><input type="number" name="cfg[wa_cap]" value="<?= (int)$acfg['wa_cap'] ?>"></div>
      <div><label>Follow up after (days)</label><input type="number" name="cfg[followup_days]" value="<?= (int)$acfg['followup_days'] ?>"></div>
      <div><label>Follow-ups at most</label><input type="number" name="cfg[max_followups]" value="<?= (int)$acfg['max_followups'] ?>"></div>
    </div>
    <p class="hint">The email limit starts low on purpose and rises as the mailbox proves it delivers: <a href="?tab=settings&amp;section=email">Operations &gt; Email</a> shows the limit in force.</p>
  </div>

  <div class="card">
    <h2>Replies and the agents</h2>
    <div class="row">
      <div><label>Email replies</label><select name="cfg[reply_mode]"><option value="draft" <?= $acfg['reply_mode'] === 'draft' ? 'selected' : '' ?>>Draft for my approval</option><option value="auto" <?= $acfg['reply_mode'] === 'auto' ? 'selected' : '' ?>>Answer simple questions by themselves</option></select></div>
      <div><label>WhatsApp Business answers</label><select name="cfg[wa_reply_mode]"><option value="auto" <?= ($acfg['wa_reply_mode'] ?? 'auto') === 'auto' ? 'selected' : '' ?>>Answer simple questions by themselves</option><option value="draft" <?= ($acfg['wa_reply_mode'] ?? '') === 'draft' ? 'selected' : '' ?>>Draft for my approval</option></select>
        <?php if (!$waBiz): ?><p class="hint">Off until the WhatsApp Business keys are in .env.</p><?php endif; ?></div>
    </div>
    <label>Agents that may work</label>
    <div class="checks mt-0">
    <?php foreach ($acfg['enabled'] as $k => $on): ?>
      <label class="check" title="<?= pm_h($agentWhat[$k] ?? '') ?>"><input type="checkbox" name="cfg[enabled][<?= pm_h((string)$k) ?>]" value="1" <?= $on ? 'checked' : '' ?>> <?= pm_h(ucfirst((string)$k)) ?><?= isset($agentWhat[$k]) ? ' <span class="muted">· ' . pm_h($agentWhat[$k]) . '</span>' : '' ?></label>
    <?php endforeach; ?>
    </div>
    <p class="hint">A ticked agent still needs an AI key: <a href="?tab=settings&amp;section=health">Health and backups</a> says which are off and why. The AI engine, its model and its daily budget are under <a href="?tab=settings&amp;section=connections">Operations &gt; Connections</a>. To run every morning without opening the app, schedule <code>schedule_agents.bat</code>.</p>
  </div>
  <div class="btns formbar"><button class="btn primary">Save the targeting</button></div>
</form>

<?php require_once __DIR__ . '/view_sendschedule.php'; pm_view_sendschedule($vb, $csrf); ?>
<?php require_once __DIR__ . '/view_msgdefaults.php'; pm_view_msgdefaults($vb, $csrf); ?>

<?php if ($custom):
    $c = (array)$settings['brands'][$vb];
    $prof = pm_brand_profile($vb);
    $row = pm_brands_custom()[$vb] ?? [];
    $tg = pm_brand_targeting($vb);
    $learned = (array)(((array)($c['profile'] ?? []))['learned'] ?? []);
    $opS = pm_onepager_status($vb); ?>
<form method="post">
  <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="brand_save"><input type="hidden" name="id" value="<?= pm_h($vb) ?>"><input type="hidden" name="_sec" value="targeting"><input type="hidden" name="back" value="targeting">
  <div class="card">
    <h2>Who buys, and the free first step</h2>
    <div class="row">
      <div><label>Who buys from you</label><input type="text" name="b[customers]" value="<?= pm_h((string)$prof['customers']) ?>"></div>
      <div><label>Free first step you offer</label><input type="text" name="b[magnet]" value="<?= pm_h((string)$prof['magnet']) ?>" placeholder="e.g. a free site visit"></div>
      <div><label>WhatsApp keyword for it</label><input type="text" name="b[cta_keyword]" value="<?= pm_h((string)$prof['cta_keyword']) ?>" maxlength="10" placeholder="QUOTE"></div>
    </div>
    <label>Who do you mainly sell to?</label>
    <div class="checks mt-0">
      <?php foreach (['business' => 'Other businesses (agents find and write to them)', 'public' => 'The public (no cold emails; social, enquiries and partners)', 'both' => 'Both'] as $k => $l): ?>
        <label class="check"><input type="radio" name="b[sell_to]" value="<?= $k ?>" <?= $prof['sell_to'] === $k ? 'checked' : '' ?>> <?= pm_h($l) ?></label>
      <?php endforeach; ?>
    </div>
    <label class="check"><input type="checkbox" name="b[daily_run]" value="1" <?= !empty($row['daily_run']) ? 'checked' : '' ?>> Run the agents every weekday morning (the scheduler starts them by itself)</label>
  </div>
  <div class="card">
    <h2>Who to target, and why</h2>
    <p class="hint mt-0">What the AI learned, which the scouts and the qualifier read to pick better leads. It is advice, not a claim about you: change anything. The kinds listed under "Types of business to look for" above are the ones that get searched.</p>
    <label>Why each kind buys <span class="muted">(one per line: kind | why they buy | a sign they need it; another sign)</span></label>
    <textarea name="b[tg_segments]" rows="5" placeholder="schools | They lose lessons when the power fails | runs on a diesel generator; has a computer lab"><?= pm_h(pm_study_segments_text($tg['segments'])) ?></textarea>
    <div class="row">
      <div><label>Not a fit (one per line; the agents skip them)</label><textarea name="b[tg_skip]" rows="3" placeholder="international chains"><?= pm_h(implode("\n", $tg['skip'])) ?></textarea></div>
      <div><label>What matters to these buyers (one per line; the qualifier leads with them)</label><textarea name="b[tg_angles]" rows="3"><?= pm_h(implode("\n", $tg['angles'])) ?></textarea></div>
    </div>
    <p class="hint"><?= !empty($learned['at']) ? 'Last studied ' . pm_h((string)$learned['at']) . (!empty($learned['from']) ? ' from ' . pm_h(implode(', ', array_map('pm_study_host', (array)$learned['from']))) : ' from your answers') . '.' : 'Not studied by the AI yet.' ?>
      <button class="btn small" form="restudy-<?= pm_h($vb) ?>" <?= pm_agents_ready() ? '' : 'disabled title="Add an AI key to .env first"' ?>>Study the business again</button></p>
  </div>
  <div class="btns formbar"><button class="btn primary">Save</button></div>
</form>
<form method="post" id="restudy-<?= pm_h($vb) ?>"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="brand_restudy"><input type="hidden" name="id" value="<?= pm_h($vb) ?>"></form>

<div class="card">
  <h2>One-page offer</h2>
  <p class="hint mt-0">One page about the business, made from its details and facts: what it does, what it offers, a free first step and how to reach it. It has no prices. It takes the place of a proposal for this business, and is emailed from a lead's card once they have written to you.</p>
  <?php if ($opS['problems']): ?><p class="hint warnt"><?= pm_h(implode(' ', $opS['problems'])) ?></p><?php endif; ?>
  <a class="btn small" href="?onepager=<?= pm_h($vb) ?>">Download the PDF</a>
</div>
<?php endif; ?>
