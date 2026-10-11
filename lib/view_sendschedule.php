<?php
/**
 * Sales > Targeting and messages > "When emails go out": the days, hours and spacing of the slow drip of approved emails, and a pause switch, per business (lib/sendschedule.php).
 * The time a single email waits for is set where it is approved (the Leads page) and on the send queue.
 */
function pm_view_sendschedule(string $vb, string $csrf): void
{
    $c = pm_ss_cfg($vb);
    $now = pm_now();
    $open = pm_ss_open($vb, $now);
    $next = pm_ss_next_open($vb, $now);
    $q = pm_send_queue(pm_leads(), $vb);
    $waiting = count(array_filter($q, fn($l) => pm_ss_after($l) > $now));
    $bn = pm_brand_name($vb);
    ?>
<form method="post" id="sendschedule" class="card mt-3">
  <input type="hidden" name="csrf" value="<?= pm_h($csrf) ?>"><input type="hidden" name="action" value="sendschedule"><input type="hidden" name="brand" value="<?= pm_h($vb) ?>">
  <h2>When emails go out · <?= pm_h($bn) ?></h2>
  <p class="hint mt-0">One approved email goes out at a time, only on the days and hours below (<?= pm_h(date_default_timezone_get()) ?> time). The send task checks every 15 minutes, so emails are never closer than that.
    The daily limit is under Limits per day above. A single email can be held for a later time when you approve it, or on the send queue.</p>
  <p class="hint"><span class="chip <?= $c['paused'] ? 'bad' : ($open ? 'ok' : 'warn') ?> dot"><?= $c['paused'] ? 'Paused' : ($open ? 'Open now' : 'Closed now') ?></span>
    <?= pm_h(date('D j M, H:i', $now)) ?>:
    <?= $c['paused'] ? 'nothing is sent until you untick Pause.' : ($open ? 'the next approved email goes out at the next check.' : ($next ? 'sending opens ' . pm_h(date('D j M, H:i', $next)) . '.' : 'no sending day is ticked.')) ?>
    <?= count($q) ?> approved email<?= count($q) === 1 ? '' : 's' ?> waiting<?= $waiting ? ', ' . $waiting . ' held for a set time' : '' ?>.</p>
  <label>Send on</label>
  <div class="checks mt-0">
    <?php foreach (PM_SS_DAYS as $n => $label): ?><label class="check"><input type="checkbox" name="ss[days][]" value="<?= $n ?>" <?= in_array($n, $c['days'], true) ? 'checked' : '' ?>> <?= pm_h($label) ?></label><?php endforeach; ?>
  </div>
  <div class="row">
    <div><label>From</label><input type="time" name="ss[from]" value="<?= pm_h($c['from']) ?>" required></div>
    <div><label>Until</label><input type="time" name="ss[to]" value="<?= pm_h($c['to']) ?>" required></div>
    <div><label>About every (minutes)</label><input type="number" name="ss[gap]" min="5" max="240" value="<?= (int)$c['gap'] ?>"></div>
  </div>
  <label class="check"><input type="checkbox" name="ss[paused]" value="1" <?= $c['paused'] ? 'checked' : '' ?>> Pause all sending for <?= pm_h($bn) ?> (approved emails wait)</label>
  <div class="btns"><button class="btn primary">Save the schedule</button></div>
</form>
<?php
}
