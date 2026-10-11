<?php
/** Operations > Team and currencies: who works here, and the currencies prices are quoted in. Shared by every business. Included by view_settings.php. */
?>
<form method="post">
  <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="settings"><input type="hidden" name="_sec" value="team">
  <div class="card" id="team"><h2>Marketing team</h2>
    <textarea name="s[team]" rows="4" placeholder="One name per line"><?= pm_h(implode("\n", (array)($settings['team'] ?? []))) ?></textarea>
    <p class="hint">Names appear in the "Working as" menu: messages are signed with the name, leads get an owner, and each person can filter to "Mine".</p>
  </div>
  <div class="card"><h2>Currencies</h2>
    <p class="hint" style="margin-top:0">Prices are in <b><?= pm_h($settings['currency']) ?></b>. Another currency is one pick on a proposal.</p>
    <table class="grid compact" data-next="<?= count($settings['currencies']) ?>"><thead><tr><th>Code</th><th>Name</th><th><?= pm_h($settings['currency']) ?> per 1</th><th>Round to</th><th></th></tr></thead><tbody>
    <?php foreach ($settings['currencies'] as $i => $c): ?>
      <tr><td><input type="text" name="s[currencies][<?= $i ?>][code]" value="<?= pm_h($c['code']) ?>" maxlength="3"></td><td><input type="text" name="s[currencies][<?= $i ?>][name]" value="<?= pm_h($c['name']) ?>"></td>
        <td><input type="number" step="any" name="s[currencies][<?= $i ?>][mpu]" value="<?= pm_h((string)round(1 / max(0.0000001, (float)$c['rate']), 4)) ?>" <?= $c['code'] === $settings['currency'] ? 'readonly' : '' ?>></td>
        <td><input type="number" step="any" name="s[currencies][<?= $i ?>][round]" value="<?= pm_h((string)$c['round']) ?>"></td>
        <td><button type="button" class="icon" onclick="this.closest('tr').remove()" title="Remove">&times;</button></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <template><tr><td><input type="text" name="s[currencies][__i__][code]" maxlength="3"></td><td><input type="text" name="s[currencies][__i__][name]"></td><td><input type="number" step="any" name="s[currencies][__i__][mpu]"></td><td><input type="number" step="any" name="s[currencies][__i__][round]" value="1"></td><td><button type="button" class="icon" onclick="this.closest('tr').remove()" title="Remove">&times;</button></td></tr></template>
    <button type="button" class="link" onclick="addRow(this)">+ Add currency</button>
    <div class="row" style="margin-top:10px">
      <div><label>Base currency</label><input type="text" name="s[currency]" value="<?= pm_h($settings['currency']) ?>"></div>
      <div><label>Quote in by default</label><select name="s[default_quote_currency]"><?php foreach ($settings['currencies'] as $c): ?><option value="<?= pm_h($c['code']) ?>" <?= $c['code'] === ($settings['default_quote_currency'] ?? $settings['currency']) ? 'selected' : '' ?>><?= pm_h($c['code']) ?></option><?php endforeach; ?></select></div>
      <div><label>Rates checked on</label><input type="date" name="s[rates_date]" value="<?= pm_h($settings['rates_date'] ?? '') ?>"></div>
    </div>
  </div>
  <div class="btns formbar"><button class="btn primary">Save the team and currencies</button></div>
</form>
