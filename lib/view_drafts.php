<?php
/**
 * What the win-back, post-sign and contact re-check agents prepared, drawn inside a lead card (MARKETING.md C2-A01).
 * Nothing here sends by itself: every button is an owner action handled in index.php (action=agents).
 * $post(id, do, extra) and $hid(name, value) are the Agents screen's own form helpers.
 */

/** HTML for one lead's open win-back / post-sign / re-verify items. '' when there are none. */
function pm_view_lead_drafts(array $l, string $csrf, callable $post, callable $hid): string
{
    $open = pm_lead_open_drafts($l);
    if (!$open) {
        return '';
    }
    $id = (string)$l['id'];
    $h = '';
    if (in_array('reverify', $open, true)) {
        $h .= '<form method="post" class="replybox" style="background:var(--okbg);border-color:#cfe3d4"><b>Contact details re-checked</b> <span class="muted">· ' . pm_h((string)$l['reverify']['at']) . '</span>'
            . '<p class="hint" style="margin:6px 0">' . pm_h((string)$l['reverify']['what']) . '. Check it looks right, then press Got it.</p>'
            . $post($id, 'reverify_seen') . '<div class="btns"><button class="btn small">Got it</button></div></form>';
    }
    if (in_array('winback', $open, true)) {
        $w = (array)$l['winback_draft'];
        $mail = filter_var($l['email'] ?? '', FILTER_VALIDATE_EMAIL) && trim((string)($w['email_body'] ?? '')) !== '';
        $waText = trim((string)($w['whatsapp'] ?? ''));
        $wa = $waText !== '' ? pm_wa_link($l, pm_wa_text($l, $waText)) : '';
        $h .= '<form method="post" class="replybox"><b>Win-back draft</b> <span class="hint">A fresh angle after a "no". Read it and send it only if the time is right.</span>'
            . $post($id, 'send_winback')
            . '<input type="text" name="email_subject" value="' . pm_h((string)($w['email_subject'] ?? '')) . '" aria-label="Win-back subject">'
            . '<textarea name="email_body" rows="4" aria-label="Win-back email">' . pm_h((string)($w['email_body'] ?? '')) . '</textarea>'
            . '<label class="hint">WhatsApp version</label><textarea name="whatsapp" rows="2" aria-label="Win-back WhatsApp">' . pm_h($waText) . '</textarea>'
            . '<div class="btns"><button class="btn small" name="do" value="save_winback">Save edits</button>'
            . ($mail ? '<button class="btn small primary" name="do" value="send_winback" onclick="return confirm(\'Send this win-back email to ' . pm_h(addslashes((string)$l['email'])) . ' now?\')">Send win-back email</button>' : '')
            . ($wa !== '' ? '<a class="btn small" href="' . pm_h($wa) . '" target="_blank" rel="noopener noreferrer">Open WhatsApp</a><button class="btn small" name="do" value="winback_wa_sent" title="Press after you pressed send in WhatsApp">I sent the WhatsApp</button>' : '')
            . '<button class="btn small danger" name="do" value="discard_winback" onclick="return confirm(\'Discard this win-back draft? It will not be drafted again for 90 days.\')">Discard</button></div></form>';
    }
    if (in_array('postsign', $open, true)) {
        $p = (array)$l['postsign'];
        $sent = (array)($l['postsign_sent'] ?? []);
        $mail = (bool)filter_var($l['email'] ?? '', FILTER_VALIDATE_EMAIL);
        $labels = ['testimonial' => 'May we quote you?', 'review' => 'Google review', 'referral' => 'Referral'];
        $h .= '<form method="post" class="replybox"><b>Thank-you asks</b> <span class="hint">They signed. Three short, friendly asks: send the ones you like, edit freely.</span>' . $post($id, 'save_postsign');
        foreach ($labels as $k => $lab) {
            $txt = trim((string)($p[$k] ?? ''));
            $wa = $txt !== '' ? pm_wa_link($l, pm_wa_text($l, $txt)) : '';
            $h .= '<label class="hint">' . pm_h($lab) . (!empty($sent[$k]) ? ' · <b>sent ' . pm_h((string)$sent[$k]) . '</b>' : '') . '</label>'
                . '<textarea name="ps_' . $k . '" rows="3" aria-label="' . pm_h($lab) . '">' . pm_h($txt) . '</textarea>'
                . '<div class="btns">' . ($mail && $txt !== '' ? '<button class="btn small primary" name="do" value="postsign_send" onclick="this.form.which.value=\'' . $k . '\';return confirm(\'Send this to ' . pm_h(addslashes((string)$l['email'])) . ' now?\')">Send by email</button>' : '')
                . ($wa !== '' ? '<a class="btn small" href="' . pm_h($wa) . '" target="_blank" rel="noopener noreferrer">WhatsApp</a>' : '')
                . ($txt !== '' ? '<button type="button" class="btn small" data-copy="' . pm_h($txt) . '">Copy</button>' : '') . '</div>';
        }
        $h .= '<input type="hidden" name="which" value=""><div class="btns"><button class="btn small" name="do" value="save_postsign">Save edits</button>'
            . '<button class="btn small" name="do" value="postsign_done" title="Hide these asks from the lead">All done</button></div></form>';
    }
    return $h;
}
