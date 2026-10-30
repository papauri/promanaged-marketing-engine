<?php
/**
 * Welcome a new customer: when a lead is Won (marked by the owner, by its progress, or live on ulendomalawi.com, lib/sx_wins.php) its card offers a short
 * welcome, written by code from the business's own words (Sales > Targeting and messages > Welcome a new customer), no AI. Talk it over can tune it; it goes
 * out only when the owner presses Send (email in their thread, or WhatsApp), or is put aside. On the lead: welcome_sent {at, how}.
 */

/** The welcome for one lead: ['subject', 'body', 'whatsapp'] (no signature: the app adds it), as the lead's business. A line whose placeholder is empty ({listing}) is left out. */
function pm_wl_render(array $lead): array
{
    return pm_dm_as(pm_brand_norm((string)($lead['brand'] ?? pm_brand())), function () use ($lead) {
        $c = pm_dm_cfg();
        $v = pm_dm_vars($lead);
        $line = fn(string $l) => stripos($l, '{listing}') !== false && $v['listing'] === '' ? '' : pm_dm_fill($l, $v); // "Your page: {listing}" before the page is live: the line is left out
        $para = fn(string $t) => trim((string)preg_replace('/\n{3,}/', "\n\n", implode("\n", array_map($line, explode("\n", str_replace("\r", '', $t))))));
        return ['subject' => pm_dm_fill($c['wl_subject'], $v), 'body' => $para($c['wl_body']), 'whatsapp' => $para($c['wl_whatsapp'])];
    });
}

/** Does the lead wait for its welcome? */
function pm_wl_due(array $lead): bool
{
    return ($lead['status'] ?? '') === 'won' && empty($lead['welcome_sent']);
}

/** The welcome went out (or was put aside). */
function pm_wl_sent(array &$lead, string $how): void
{
    $lead['welcome_sent'] = ['at' => date('Y-m-d H:i'), 'how' => $how];
    pm_lead_note($lead, $how === 'skipped' ? 'Welcome not sent' : 'Welcome sent by ' . $how);
    if ($how !== 'skipped') {
        $lead['last_contacted'] = date('Y-m-d H:i');
    }
}

/** The welcome on the lead's card, ready to send. '' once it went or was put aside. */
function pm_wl_panel(array $lead, callable $post, bool $waOn): string
{
    if (!pm_wl_due($lead)) {
        return '';
    }
    $m = pm_wl_render($lead);
    $mail = (bool)filter_var($lead['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $wa = $waOn ? pm_wa_link($lead, '') : '';
    return '<form method="post" class="replybox ok">' . $post('welcome_send') . '<div class="rx-head"><b>Welcome them</b><span class="muted t-sm">They are a customer now: a short welcome from ' . pm_h(pm_brand_name(pm_brand_norm((string)($lead['brand'] ?? '')))) . '. Nothing goes until you send it.</span></div>'
        . ($mail ? '<input type="text" name="subject" value="' . pm_h($m['subject']) . '" aria-label="Subject"><textarea name="body" rows="8" data-chat="followup" aria-label="Welcome by email">' . pm_h($m['body']) . '</textarea>' : '')
        . ($wa !== '' ? '<label class="hint">WhatsApp version</label><textarea name="whatsapp" rows="3" data-chat="whatsapp" aria-label="Welcome on WhatsApp">' . pm_h($m['whatsapp']) . '</textarea>' : '')
        . '<div class="btns">' . ($mail ? '<button class="btn small primary" onclick="return confirm(\'Send this welcome by email now?\')">Send the welcome</button>' : '')
        . ($wa !== '' ? '<button class="btn small" name="do" value="welcome_wa" formtarget="_blank">Send on WhatsApp</button>' : '')
        . (!$mail && $wa === '' ? '<span class="hint">No email address or mobile number: add one under To.</span>' : '')
        . '<button class="btn small ghost" name="do" value="welcome_skip" formnovalidate>Not needed</button></div></form>';
}
