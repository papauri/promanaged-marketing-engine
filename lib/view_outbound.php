<?php
/** Echo-functions for the Agents and Settings screens: send queue, embed card, email health. Functions only; nothing runs on include. */
require_once __DIR__ . '/outbound.php';

/** Approved emails waiting to go out, best first, with when each is expected to send. $leads = all leads. */
function pm_view_send_queue(array $leads): void
{
    $cfg = pm_agents_config();
    $cap = pm_effective_send_cap($cfg);
    $left = max(0, $cap - pm_sent_today($leads));
    $q = pm_send_queue($leads, pm_brand());
    $br = pm_send_breaker();
    ?>
    <div class="card"><h2>Send queue <span class="pill"><?= count($q) ?></span></h2>
      <p class="hint">Approved emails go out one at a time, Mon-Fri 08:00-16:30, a few minutes apart. Today: <?= pm_sent_today($leads) ?> sent, room for <?= $left ?> more (the daily limit rises as the mailbox warms up).
        <?= pm_send_window() ? '' : 'It is outside sending hours now.' ?></p>
      <?php if ($br['blocked']): ?><p class="flash err"><?= pm_h($br['why']) ?></p><?php endif; ?>
      <?php if (!$q): ?><p class="hint">Nothing approved yet. Approve drafts to start the drip.</p><?php endif; ?>
      <?php $i = 0; foreach ($q as $l): ?>
        <div class="row" style="display:flex;gap:10px;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--line)">
          <span><b><?= pm_h($l['name'] ?? '') ?></b> <span class="muted"><?= pm_h($l['email'] ?? '') ?></span></span>
          <span><span class="pill"><?= (int)($l['score'] ?? 0) ?></span> <span class="muted"><?= pm_h(pm_approved_eta($l, $i++, $left)) ?></span></span>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
}

/** Link, iframe snippet and QR code for the public enquiry form. */
function pm_view_embed_card(): void
{
    $b = pm_brand();
    $base = pm_app_url();
    $q = $b === 'travel' ? '?b=travel' : '?b=promanaged';
    $links = ['Enquiry form' => $q . '&src=site', 'Free website check' => $q . '&mode=check&src=site'] + ($b === 'travel' ? ['Host sign-up (list your stay)' => $q . '&mode=host&src=site'] : []);
    ?>
    <div class="card"><h2>Enquiry form for your website</h2>
      <?php if ($base === ''): ?><p class="flash err">Not reachable yet: APP_URL is empty in .env. Visitors cannot open this page until the app is hosted online and APP_URL is set.</p><?php endif; ?>
      <p class="hint">Visitors fill in a short form and it arrives as a lead scored 85, with the owner told straight away. Nothing is sent to them except one short receipt.</p>
      <?php foreach ($links as $label => $path): $url = ($base !== '' ? $base : 'https://YOUR-SITE') . '/enquire.php' . $path; ?>
        <div style="margin:10px 0"><b><?= pm_h($label) ?></b>
          <div><input type="text" readonly value="<?= pm_h($url) ?>" onclick="this.select()" style="width:100%"></div>
          <?php if ($label === 'Enquiry form'): ?><div class="hint">Embed on a page: <code><?= pm_h('<iframe src="' . $url . '&embed=1" width="100%" height="640" style="border:0" title="Contact us"></iframe>') ?></code></div>
            <?php if ($base !== '' && class_exists('TCPDF2DBarcode')): $qr = (new TCPDF2DBarcode($url, 'QRCODE,M'))->getBarcodeSVGcode(4, 4, 'black'); ?>
              <div class="hint">QR code for posters and cards (made on this computer):</div><div style="width:150px;background:#fff;padding:6px;border:1px solid var(--line)"><?= $qr ?></div>
            <?php else: ?><div class="hint">QR code: paste the link above into any QR generator you trust.</div><?php endif; ?>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
}

/** Sender-domain checks (SPF, DMARC, DKIM), warm-up limit and the bounce breaker. */
function pm_view_email_health(): void
{
    $s = pm_settings();
    $cfg = pm_agents_config();
    $from = (string)(($s['smtp']['from_email'] ?? '') ?: ($s['email'] ?? ''));
    $dom = pm_email_domain($from);
    $a = pm_email_auth_check($dom);
    $br = pm_send_breaker();
    $cap = pm_effective_send_cap($cfg);
    $fs = (string)(pm_ob_read('send_stats')['brands'][pm_brand()]['first_send'] ?? '');
    $chip = fn(string $name, array $r) => '<span class="pill ' . ($r['status'] === 'ok' ? 'hot' : 'warn') . '" title="' . pm_h((string)$r['detail']) . '">' . pm_h($name) . ': ' . ($r['status'] === 'ok' ? 'OK' : ($r['status'] === 'weak' ? 'weak' : 'missing')) . '</span> ';
    ?>
    <div class="card"><h2>Email health</h2>
      <p class="hint">Sending from <b><?= pm_h($from ?: 'no From address set') ?></b>.</p>
      <?php if ($a['checked']): ?><p><?= $chip('SPF', $a['spf']) . $chip('DMARC', $a['dmarc']) . $chip('DKIM', $a['dkim']) ?></p><?php endif; ?>
      <?php foreach ($a['advice'] as $t): ?><p class="hint"><?= pm_h($t) ?></p><?php endforeach; ?>
      <p>Daily limit now: <b><?= $cap ?></b> <span class="muted">(you allowed up to <?= (int)$cfg['send_cap'] ?>; it starts at 3 and rises by 2 each week of sending<?= $fs !== '' ? ', first email sent ' . pm_h($fs) : ', no email sent yet' ?>)</span></p>
      <p><span class="pill <?= $br['blocked'] ? 'warn' : 'hot' ?>"><?= $br['blocked'] ? 'Sending paused' : 'Bounce check OK' ?></span> <span class="muted"><?= pm_h($br['why']) ?></span></p>
    </div>
    <?php
}
