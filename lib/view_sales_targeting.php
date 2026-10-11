<?php
/**
 * Sales > Targeting and messages: how the sales team is told to work. What we sell and who to look for, the daily limits, how replies are handled, which agents are on, the standard wording of
 * the first email (Travel Malawi), the default first message and signatures of every business, and for a business added in the app who buys from it and its free first step. The team's roster and diary are here too. The leads themselves are Sales > Leads.
 * Needs $vb, $settings, $csrf.
 */
pm_brand_set($vb);
$bname = pm_brand_title($settings, $vb);
$acfg = pm_agents_config($vb);
$custom = pm_brand_is_custom($vb);
$travel = $vb === 'travel';
$waBiz = pm_wa_biz_cfg()['ready'];
$agent = fn(string $do, string $extra = '') => '<input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="agents"><input type="hidden" name="do" value="' . $do . '"><input type="hidden" name="bt" value="targeting">' . $extra;
$data = pm_home_data($vb);
$crew = pm_home_staff($vb, $data)['sales'];
$agentLog = pm_home_activity($vb, 20);
$us = pm_usage_today();
arsort($us['agents']);
echo pm_ui_head('Targeting and messages · ' . $bname, 'Who the team looks for, how much it may do in a day, how it answers, and the wording it uses. Nothing here sends anything.') . pm_ui_strip('targeting');
?>

<form method="post">
  <?= $agent('config') ?>
  <div class="card">
    <h2>What we sell and who we look for</h2>
    <?php if (!$travel): ?><label>What we sell (one line each, "Name: description")</label>
    <textarea name="cfg[offerings]" rows="4"><?= pm_h(implode("\n", (array)$acfg['offerings'])) ?></textarea><?php endif; ?>
    <div class="row">
      <div><label>Types of business to look for (comma separated)</label><input type="text" name="cfg[sectors]" value="<?= pm_h(implode(', ', (array)$acfg['sectors'])) ?>"></div>
      <div><label>Cities to search (comma separated)</label><input type="text" name="cfg[cities]" value="<?= pm_h(implode(', ', (array)$acfg['cities'])) ?>"></div>
    </div>
    <?php if ($custom): ?><label>Existing clients (one per line; they are never cold-pitched)</label>
    <textarea name="cfg[existing_clients]" rows="3"><?= pm_h(implode("\n", (array)($acfg['existing_clients'] ?? []))) ?></textarea><?php endif; ?>
  </div>

  <div class="card">
    <h2>How much in a day</h2>
    <div class="row">
      <div><label>New leads per day</label><input type="number" name="cfg[new_per_day]" value="<?= (int)$acfg['new_per_day'] ?>"></div>
      <div><label>Searches per day</label><input type="number" name="cfg[scouts_per_day]" value="<?= (int)$acfg['scouts_per_day'] ?>"></div>
      <div><label>Emails per day (limit)</label><input type="number" name="cfg[send_cap]" value="<?= (int)$acfg['send_cap'] ?>"></div>
      <div><label>WhatsApp messages per day (limit)</label><input type="number" name="cfg[wa_cap]" value="<?= (int)$acfg['wa_cap'] ?>"></div>
      <div><label>Follow up after (days)</label><input type="number" name="cfg[followup_days]" value="<?= (int)$acfg['followup_days'] ?>"></div>
      <div><label>Follow-ups at most</label><input type="number" name="cfg[max_followups]" value="<?= (int)$acfg['max_followups'] ?>"></div>
    </div>
    <p class="hint">The email limit starts low on purpose and rises as the mailbox proves it delivers: <a href="?tab=settings&amp;section=email">Operations &gt; Email</a> shows the limit in force.</p>
  </div>

  <div class="card">
    <h2>Replies, and the AI that writes</h2>
    <div class="row">
      <div><label>Email replies</label><select name="cfg[reply_mode]"><option value="draft" <?= $acfg['reply_mode'] === 'draft' ? 'selected' : '' ?>>Draft for my approval</option><option value="auto" <?= $acfg['reply_mode'] === 'auto' ? 'selected' : '' ?>>Auto-send simple, safe replies</option></select></div>
      <div><label>WhatsApp Business answers<?= $waBiz ? '' : ' (off until WA_BIZ keys are in .env)' ?></label><select name="cfg[wa_reply_mode]"><option value="auto" <?= ($acfg['wa_reply_mode'] ?? 'auto') === 'auto' ? 'selected' : '' ?>>Answer simple questions by themselves</option><option value="draft" <?= ($acfg['wa_reply_mode'] ?? 'auto') === 'draft' ? 'selected' : '' ?>>Draft for my approval</option></select></div>
      <div><label>Agents at once</label><input type="number" name="cfg[parallel]" value="<?= (int)$acfg['parallel'] ?>"></div>
      <div><label>Daily AI budget (tokens; 0 = none)</label><input type="number" name="cfg[daily_token_budget]" value="<?= (int)$acfg['daily_token_budget'] ?>"></div>
      <div><label>Quality</label><select name="cfg[quality]"><option value="economy" <?= $acfg['quality'] === 'economy' ? 'selected' : '' ?>>Economy (cheapest)</option><option value="balanced" <?= $acfg['quality'] === 'balanced' ? 'selected' : '' ?>>Balanced</option></select></div>
      <div><label>Gemini model</label><select name="cfg[gemini_model]"><option value="economy" <?= $acfg['gemini_model'] === 'economy' ? 'selected' : '' ?>>Economy (recommended)</option><option value="auto" <?= $acfg['gemini_model'] === 'auto' ? 'selected' : '' ?>>Spread across the newest 3</option>
        <?php foreach (pm_gemini_top_models() as $gm): ?><option value="<?= pm_h($gm) ?>" <?= $acfg['gemini_model'] === $gm ? 'selected' : '' ?>><?= pm_h($gm) ?></option><?php endforeach; ?></select></div>
    </div>
    <p class="hint"><b>AI use today:</b> <?= number_format($us['total']) ?> tokens in <?= (int)$us['calls'] ?> calls<?= pm_budget() ? ' of a ' . number_format(pm_budget()) . ' budget' : '' ?><?= $us['agents'] ? ' (' . pm_h(implode(', ', array_map(fn($k, $v) => str_replace('_', ' ', $k) . ' ' . number_format($v), array_keys(array_slice($us['agents'], 0, 6, true)), array_slice($us['agents'], 0, 6, true)))) . ')' : '' ?>.
      Searching with <?= pm_provider(true) === 'gemini' ? 'Gemini' : 'Claude' ?>, writing with <?= pm_provider(false) === 'gemini' ? 'Gemini' : 'Claude' ?>. Newest Gemini models today: <?= pm_h(implode(', ', pm_gemini_top_models())) ?>.</p>
  </div>

  <div class="card">
    <h2>Who is on the team</h2>
    <p class="hint mt-0">Tick the agents that may work. An agent that is ticked still needs an AI key; the reason an agent is off is shown beside it.</p>
    <div class="crew">
    <?php foreach ($acfg['enabled'] as $k => $on): ?>
      <label class="check"><input type="checkbox" name="cfg[enabled][<?= $k ?>]" value="1" <?= $on ? 'checked' : '' ?>> <?= pm_h(ucfirst($k)) ?></label>
    <?php endforeach; ?>
    </div>
    <ul class="roster">
    <?php foreach ($crew as $m): ?><li class="<?= $m['on'] ? 'on' : 'off' ?>"><div><b><?= pm_h($m['name']) ?></b><small><?= pm_h($m['what']) ?></small></div><em><?= $m['on'] ? 'on duty' : pm_h($m['why']) ?></em></li><?php endforeach; ?>
    </ul>
    <p class="hint">The Reply agent answers replies, the Follow-up agent nudges quiet leads, and the Director sets the day's strategy. To run every morning without opening the app, schedule <code>schedule_agents.bat</code>.</p>
  </div>
  <div class="btns formbar"><button class="btn primary">Save the targeting</button></div>
</form>
<form method="post" class="inl"><?= $agent('refresh_models') ?><button class="btn small">Refresh the Gemini model list</button></form>

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

<?php if ($travel): $he = pm_tm_email_cfg(); $hk = pm_tm_email_check(); $hn = fn(string $k) => 's[travel][host_email][' . $k . ']'; ?>
<form method="post">
  <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="settings"><input type="hidden" name="_sec" value="messages"><input type="hidden" name="back" value="targeting">
  <div class="card"><h2>First email to a stay</h2>
    <p class="hint mt-0">Every first email to a stay opens with a line the AI writes for that place, then this text, which is the same for all of them. Write only what is true: nothing here is checked against the platform. Use <code>{link}</code> in a step where the link goes (one link is allowed in an email).</p>
    <label>What Travel Malawi is</label><textarea name="<?= $hn('intro') ?>" rows="2"><?= pm_h((string)$he['intro']) ?></textarea>
    <div class="row">
      <div><label>Heading for what a host gets</label><input type="text" name="<?= $hn('benefits_title') ?>" value="<?= pm_h((string)$he['benefits_title']) ?>"></div>
      <div><label>Heading for the steps</label><input type="text" name="<?= $hn('steps_title') ?>" value="<?= pm_h((string)$he['steps_title']) ?>"></div>
      <div><label>The link</label><input type="text" name="<?= $hn('link') ?>" value="<?= pm_h((string)$he['link']) ?>" placeholder="https://ulendomalawi.com"></div>
    </div>
    <div class="row">
      <div><label>What a host gets (one per line)</label><textarea name="<?= $hn('benefits') ?>" rows="4"><?= pm_h(implode("\n", $he['benefits'])) ?></textarea></div>
      <div><label>The set-up steps (one per line)</label><textarea name="<?= $hn('steps') ?>" rows="4"><?= pm_h(implode("\n", $he['steps'])) ?></textarea></div>
    </div>
    <div class="row">
      <div><label>That it is free</label><input type="text" name="<?= $hn('free') ?>" value="<?= pm_h((string)$he['free']) ?>"></div>
      <div><label>Why it is free (leave empty to say nothing)</label><input type="text" name="<?= $hn('why') ?>" value="<?= pm_h((string)$he['why']) ?>"></div>
      <div><label>The question at the end</label><input type="text" name="<?= $hn('closing') ?>" value="<?= pm_h((string)$he['closing']) ?>"></div>
    </div>
    <p class="hint <?= $hk['problems'] ? 'warnt' : '' ?>">With a sample opening it is <b><?= (int)$hk['words'] ?></b> words (first emails may be 20 to 190).<?= $hk['problems'] ? ' Fix before it can be sent: ' . pm_h(implode(' ', $hk['problems'])) : ' It passes the wording rules.' ?> Saved wording applies to new drafts; "Redraft" on the Leads screen applies it to unsent ones.</p>
    <details class="more"><summary>See a sample email</summary><pre class="mt-2" style="white-space:pre-wrap;font:13px/1.5 inherit"><?= pm_h($hk['subject'] . "\n\n" . $hk['body']) ?></pre></details>
  </div>
  <div class="btns formbar"><button class="btn primary">Save the wording</button></div>
</form>
<?php endif; ?>

<?php require_once __DIR__ . '/view_sendschedule.php'; pm_view_sendschedule($vb, $csrf); ?>
<?php require_once __DIR__ . '/view_msgdefaults.php'; pm_view_msgdefaults($vb, $csrf); ?>

<h3>The team's diary</h3>
<div class="card">
  <?php if (!$agentLog): ?><p class="hint m-0">Nothing yet. When the agents run, what they did is written here.</p><?php endif; ?>
  <?php foreach ($agentLog as $e): ?><p class="hint log<?= $e['error'] ? ' t-bad' : '' ?>"><?= pm_h($e['at'] !== '' ? date('j M H:i', strtotime($e['at'])) : '') ?> · <b><?= pm_h($e['agent']) ?></b> · <?= pm_h($e['msg']) ?></p><?php endforeach; ?>
</div>
