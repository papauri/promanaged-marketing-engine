<?php
/**
 * Admin deletes: an admin can remove anything a team member cannot (index.php action=admin_delete, refused for anyone else).
 *   lead       one lead                    leads     the ticked leads           archived   the ticked leads in the archive, for good
 *   msg        one message of a conversation (a reply that was junk or a mistake; when no answer from them is left, the lead goes back to "contacted")
 *   note       one note on a lead          proposal  one row of the proposal history (the PDF files stay in output/)
 * A STOP is remembered as a fingerprint even when its lead is deleted (pm_suppress_remember), so a deleted lead that opted out is never written to again.
 * Deleting on Facebook (posts, comments) and of posts, content and campaigns lives on those pages already.
 */

/** Removes leads by id, only of businesses the person may open. Returns how many went. */
function pm_adm_delete_leads(array $ids): int
{
    $ids = array_slice(array_values(array_unique(array_map('strval', $ids))), 0, 500);
    $L = pm_leads();
    $n = 0;
    foreach ($ids as $id) {
        if (isset($L[$id]) && pm_user_can_brand(pm_brand_norm($L[$id]['brand'] ?? 'promanaged'))) {
            pm_suppress_remember($L[$id]); // a STOP outlives the lead
            unset($L[$id]);
            $n++;
        }
    }
    if ($n) {
        pm_leads_save($L);
    }
    return $n;
}

/** Removes leads from the archive for good. Returns how many went. */
function pm_adm_delete_archived(array $ids): int
{
    $ids = array_flip(array_map('strval', $ids));
    $n = 0;
    pm_update('leads_archive', function (array $a) use ($ids, &$n) {
        foreach (array_keys($a) as $id) {
            if (isset($ids[(string)$id]) && pm_user_can_brand(pm_brand_norm($a[$id]['brand'] ?? 'promanaged'))) {
                pm_suppress_remember($a[$id]);
                unset($a[$id]);
                $n++;
            }
        }
        return $a;
    }, fn() => []);
    return $n;
}

/** Removes message $i of a lead's conversation. When no answer from them is left, the reply state goes too and a "replied" lead is "contacted" again. */
function pm_adm_delete_msg(string $id, int $i): array
{
    $L = pm_leads();
    if (!isset($L[$id]['thread'][$i])) {
        return [false, 'That message is already gone.'];
    }
    $l = &$L[$id];
    $was = $l['thread'][$i];
    unset($l['thread'][$i]);
    $l['thread'] = array_values($l['thread']);
    if (($was['dir'] ?? '') === 'in' && !array_filter($l['thread'], fn($m) => ($m['dir'] ?? '') === 'in')) {
        unset($l['awaiting_reply_since'], $l['reply_intent'], $l['last_reply'], $l['want_proposal']);
        $l['reply_draft'] = null;
        if (($l['status'] ?? '') === 'replied') {
            $l['status'] = !empty($l['sent']) || !empty($l['wa_sent']) ? 'contacted' : 'new';
        }
    }
    pm_lead_note($l, 'Admin deleted a message from ' . (($was['dir'] ?? '') === 'in' ? 'them' : 'us') . ' (' . ($was['at'] ?? '') . ')');
    unset($l);
    pm_leads_save($L);
    return [true, 'Message deleted.'];
}

/** Removes note $i (as stored, oldest first) of a lead. */
function pm_adm_delete_note(string $id, int $i): array
{
    $L = pm_leads();
    if (!isset($L[$id]['notes'][$i])) {
        return [false, 'That note is already gone.'];
    }
    unset($L[$id]['notes'][$i]);
    $L[$id]['notes'] = array_values($L[$id]['notes']);
    pm_leads_save($L);
    return [true, 'Note deleted.'];
}

/** Removes row $i of the proposal history (the PDFs stay in output/). */
function pm_adm_delete_proposal(int $i): array
{
    $gone = '';
    pm_update('history', function (array $h) use ($i, &$gone) {
        if (isset($h[$i])) {
            $gone = (string)($h[$i]['ref'] ?? 'proposal');
            array_splice($h, $i, 1);
        }
        return $h;
    }, fn() => []);
    return $gone === '' ? [false, 'That proposal is already gone.'] : [true, "Proposal $gone removed from the history. Its PDF files are kept in the output folder."];
}

/** One small "Delete" button for an admin (empty for anyone else). $fields are the hidden inputs; $ask is the "are you sure" question. */
function pm_adm_btn(string $csrf, string $what, array $fields, string $ask, string $label = 'Delete', string $cls = 'btn small danger'): string
{
    if (!pm_user_is_admin()) {
        return '';
    }
    $h = '<form method="post" class="admdel"><input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="admin_delete"><input type="hidden" name="what" value="' . pm_h($what) . '">';
    foreach ($fields as $k => $v) {
        $h .= '<input type="hidden" name="' . pm_h($k) . '" value="' . pm_h((string)$v) . '">';
    }
    return $h . '<button class="' . pm_h($cls) . '" data-confirm="' . pm_h($ask) . '" title="Admin: delete">' . $label . '</button></form>';
}
