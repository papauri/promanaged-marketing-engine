<?php
/**
 * Operations: the machinery of the firm. One screen, five pages (?tab=settings&section=...), each saving only its own part:
 *   business     company details, logo, signing, link in messages (and hide or delete a business you added)
 *   email        mail server, login, mailbox for replies, and whether the mail will reach inboxes
 *   connections  Facebook and the other platforms, the picture engine, the scheduler, the website enquiry form
 *   health       what is connected and what is missing, weekly backups
 *   team         who works here and the currencies
 * What the AI knows is under Brand > Voice, who the agents look for is under Sales > Targeting, auto-publish is under Social > Plan: each thing is edited in exactly one place.
 * Needs $vb, $settings, $csrf, $section.
 */
$sec = in_array($section ?? '', ['business', 'email', 'connections', 'health', 'team'], true) ? $section : PM_UI_DEFAULT_SECTION;
$bname = pm_brand_title($settings, $vb);
$isCustom = pm_brand_is_custom($vb);
$heads = [
    'business' => ['Business details · ' . $bname, 'The name, contact details, logo and signing of each business. The work itself lives in its own department.', '<a class="btn primary" href="?tab=business&amp;new=1">+ Add a business</a>'],
    'email' => ['Email · ' . $bname, 'The mailbox every email goes out from, and whether it will reach inboxes.', ''],
    'connections' => ['Connections · ' . $bname, 'Facebook and the other platforms, the picture engine, the scheduler and the website enquiry form.', ''],
    'health' => ['Health and backups', 'What is connected, what is missing, and the weekly copies of your data.', ''],
    'team' => ['Team and currencies', 'Who works here and the currencies prices are quoted in. Shared by every business.', ''],
];
echo pm_ui_head($heads[$sec][0], $heads[$sec][1], $heads[$sec][2]) . pm_ui_strip('settings', '', $sec);
require __DIR__ . '/view_ops_' . $sec . '.php';
?>
<?php foreach (array_reverse(pm_brand_ids()) as $bk): ?>
<form method="post" id="linkrefresh-<?= $bk ?>"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="link_refresh"><input type="hidden" name="brand" value="<?= $bk ?>"></form>
<form method="post" id="smtpcheck-<?= $bk ?>"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="smtp_check"><input type="hidden" name="brand" value="<?= $bk ?>"></form>
<?php endforeach; ?>
