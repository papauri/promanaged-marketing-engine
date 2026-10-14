<?php
/** Operations > Team and currencies: who can sign in (admins only), who works here, and the currencies prices are quoted in. Shared by every business. Included by view_settings.php. */
if (pm_user_is_admin()):
    $acUsers = pm_users();
    $acMe = (string)(pm_user()['username'] ?? '');
    $acHid = fn(string $do, string $u = '') => '<input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="access"><input type="hidden" name="do" value="' . $do . '"><input type="hidden" name="username" value="' . pm_h($u) . '">';
    $acBiz = function (array $sel, bool $lock = false): string {
        $h = '<div class="acc-biz">';
        foreach (pm_brand_ids() as $bid) {
            $h .= '<label class="tick"><input type="checkbox" name="brands[]" value="' . pm_h($bid) . '"' . ($lock || in_array('*', $sel, true) || in_array($bid, $sel, true) ? ' checked' : '') . ($lock ? ' disabled' : '') . '> ' . pm_h(pm_brand_name($bid)) . '</label>';
        }
        return $h . '</div>';
    };
?>
<div class="card" id="access"><h2>Team access</h2>
  <p class="hint mt-0">Who can sign in, and which businesses each person can open. Accounts are kept in .env. A person turned off, deleted or given a new password is signed out on their next click. Admins see every business and manage this list.</p>
  <?php if (!$acUsers): ?><p class="hint">No accounts yet: the app is open to anyone on this computer. Add an admin below and everyone signs in from then on.</p><?php endif; ?>
  <div class="acc-list">
  <?php foreach ($acUsers as $acName => $acU): $acAdmin = $acU['role'] === 'admin'; ?>
    <div class="acc-row<?= $acU['on'] ? '' : ' off' ?>">
      <div class="acc-who"><?= pm_auth_av(['username' => $acName, 'name' => $acU['name']], 'lg') ?><div><b><?= pm_h($acU['name']) ?></b><small>@<?= pm_h($acName) ?> · <?= $acAdmin ? 'Admin' : 'Team member' ?><?= $acName === $acMe ? ' · you' : '' ?></small></div>
        <span class="chip <?= $acU['on'] ? 'ok' : 'bad' ?>"><?= $acU['on'] ? 'Can sign in' : 'Turned off' ?></span></div>
      <form method="post" class="acc-part"><?= $acHid('brands', $acName) ?><label>Businesses they can open</label><?= $acBiz($acU['brands'], $acAdmin) ?>
        <?php if (!$acAdmin): ?><div class="acc-inline"><input type="text" name="name" value="<?= pm_h($acU['name']) ?>" aria-label="Name shown" placeholder="Name shown"><button class="btn small">Save</button></div><?php endif; ?></form>
      <form method="post" class="acc-part"><?= $acHid('password', $acName) ?><label for="pw-<?= pm_h($acName) ?>">New password</label><div class="acc-inline"><input id="pw-<?= pm_h($acName) ?>" type="password" name="password" minlength="8" autocomplete="new-password" placeholder="At least 8 characters" required><button class="btn small">Set password</button></div></form>
      <?php if ($acName !== $acMe): ?><div class="btns acc-acts">
        <form method="post"><?= $acHid('toggle', $acName) ?><button class="btn small"><?= $acU['on'] ? 'Turn off' : 'Turn on' ?></button></form>
        <form method="post"><?= $acHid('delete', $acName) ?><button class="btn small danger" data-confirm="<?= pm_h("Delete the account $acName? They can no longer sign in. Their notes and leads stay.") ?>">Delete</button></form>
      </div><?php else: ?><div></div><?php endif; ?>
    </div>
  <?php endforeach; ?>
  </div>
  <details class="acc-add"<?= $acUsers ? '' : ' open' ?>><summary>+ Add a person</summary>
    <form method="post"><?= $acHid('add') ?>
      <div class="row">
        <div><label>Username</label><input type="text" name="username" required pattern="[A-Za-z0-9._\-]{2,32}" autocapitalize="none" placeholder="e.g. jayne"></div>
        <div><label>Name shown</label><input type="text" name="name" placeholder="e.g. Jayne"></div>
        <div><label>Password</label><input type="password" name="password" minlength="8" autocomplete="new-password" required></div>
        <div><label>Role</label><select name="role"><option value="user">Team member</option><option value="admin"<?= $acUsers ? '' : ' selected' ?>>Admin</option></select></div>
      </div>
      <label>Businesses they can open</label><?= $acBiz(['*']) ?>
      <p class="hint">All ticked means every business, including ones added later. An admin always sees every business.</p>
      <div class="btns"><button class="btn primary">Add the account</button></div>
    </form>
  </details>
</div>
<?php endif; ?>
<form method="post">
  <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="settings"><input type="hidden" name="_sec" value="team">
  <div class="card" id="team"><h2>Marketing team</h2>
    <textarea name="s[team]" rows="4" placeholder="One name per line"><?= pm_h(implode("\n", (array)($settings['team'] ?? []))) ?></textarea>
    <p class="hint">Names leads can be given to as owner. Whoever is signed in works under their account name: messages are signed with it, leads they send get them as owner, and they can filter to "Mine".</p>
  </div>
  <div class="card" id="agentnames"><h2>Your AI team</h2>
    <p class="hint mt-0">The AI agents and what each one does. Give any of them a new name: one word, no two alike. Leave a box empty for the usual name. The new names show everywhere at once, also in the past conversations under Audit › Agent conversations.</p>
    <table class="grid compact"><thead><tr><th>Agent</th><th>Name</th><th>What they do</th></tr></thead><tbody>
    <?php foreach (PM_AGENT_ROLES as $ad => $arole): ?>
      <tr><td><?= pm_h($arole) ?></td><td><input type="text" name="s[agent_names][<?= $ad ?>]" value="<?= pm_h((string)(((array)($settings['agent_names'] ?? []))[$ad] ?? '')) ?>" placeholder="<?= pm_h(PM_AGENT_FIRST[$ad]) ?>" maxlength="20" aria-label="Name for the <?= pm_h($arole) ?>"></td><td class="t-sm"><?= pm_h(PM_AGENT_JOBS[$ad]) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
  </div>
  <div class="card"><h2>Currencies</h2>
    <p class="hint mt-0">Prices are in <b><?= pm_h($settings['currency']) ?></b>. Another currency is one pick on a proposal.</p>
    <table class="grid compact" data-next="<?= count($settings['currencies']) ?>"><thead><tr><th>Code</th><th>Name</th><th><?= pm_h($settings['currency']) ?> per 1</th><th>Round to</th><th></th></tr></thead><tbody>
    <?php foreach ($settings['currencies'] as $i => $c): ?>
      <tr><td><input type="text" name="s[currencies][<?= $i ?>][code]" value="<?= pm_h($c['code']) ?>" maxlength="3"></td><td><input type="text" name="s[currencies][<?= $i ?>][name]" value="<?= pm_h($c['name']) ?>"></td>
        <td><input type="number" step="any" name="s[currencies][<?= $i ?>][mpu]" value="<?= pm_h((string)round(1 / max(0.0000001, (float)$c['rate']), 4)) ?>" <?= $c['code'] === $settings['currency'] ? 'readonly' : '' ?>></td>
        <td><input type="number" step="any" name="s[currencies][<?= $i ?>][round]" value="<?= pm_h((string)$c['round']) ?>"></td>
        <td><button type="button" class="icon" onclick="this.closest('tr').remove()" title="Remove" aria-label="Remove this row">&times;</button></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <template><tr><td><input type="text" name="s[currencies][__i__][code]" maxlength="3"></td><td><input type="text" name="s[currencies][__i__][name]"></td><td><input type="number" step="any" name="s[currencies][__i__][mpu]"></td><td><input type="number" step="any" name="s[currencies][__i__][round]" value="1"></td><td><button type="button" class="icon" onclick="this.closest('tr').remove()" title="Remove" aria-label="Remove this row">&times;</button></td></tr></template>
    <button type="button" class="link" onclick="addRow(this)">+ Add currency</button>
    <div class="row mt-2">
      <div><label>Base currency</label><input type="text" name="s[currency]" value="<?= pm_h($settings['currency']) ?>"></div>
      <div><label>Quote in by default</label><select name="s[default_quote_currency]"><?php foreach ($settings['currencies'] as $c): ?><option value="<?= pm_h($c['code']) ?>" <?= $c['code'] === ($settings['default_quote_currency'] ?? $settings['currency']) ? 'selected' : '' ?>><?= pm_h($c['code']) ?></option><?php endforeach; ?></select></div>
      <div><label>Rates checked on</label><input type="date" name="s[rates_date]" value="<?= pm_h($settings['rates_date'] ?? '') ?>"></div>
    </div>
  </div>
  <div class="btns formbar"><button class="btn primary">Save the team, names and currencies</button></div>
</form>
