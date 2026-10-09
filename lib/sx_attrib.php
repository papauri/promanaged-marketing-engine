<?php
/**
 * Where did each lead come from? Post codes (ref K7Q2), tagged links (src / utm_campaign), referrals (r-<lead id>), hand-logged enquiries,
 * and the "From post" choice on the lead card.
 */
require_once __DIR__ . '/sx_replies.php';

/** Link-in-bio codes (4 letters, so a WhatsApp "ref" can carry them) and the tag they stand for. */
const PM_BIO_REFS = ['BIIG' => 'bio_ig', 'BITT' => 'bio_tiktok', 'BIFB' => 'bio_fb', 'BIWA' => 'bio_wa'];

/** A post code in free text: "(ref K7Q2)", "ref: K7Q2" or "say K7Q2". Null when none. */
function pm_parse_ref(string $text): ?string
{
    if (preg_match('/\(\s*ref[\s:#]*([A-Za-z0-9]{4})\s*\)/i', $text, $m) || preg_match('/\bref[\s:#]+([A-Za-z0-9]{4})\b/i', $text, $m)) {
        return strtoupper($m[1]);
    }
    if (preg_match('/\bsay\s+([A-Za-z0-9]{4})\b/', $text, $m)) {
        $c = $m[1];
        return ($c === strtoupper($c) || preg_match('/\d/', $c)) ? strtoupper($c) : null; // "say that" is not a code
    }
    return null;
}

/** What a lead tells us about the post it came from: ['source_post','source_ref','source_pillar','source_format']. The code comes from the text first, then the lead. */
function pm_lead_attrib(array $lead, string $text): array
{
    $ref = pm_parse_ref($text) ?: strtoupper(trim((string)($lead['source_ref'] ?? '')));
    $out = ['source_post' => (string)($lead['source_post'] ?? ''), 'source_ref' => $ref, 'source_pillar' => '', 'source_format' => ''];
    if ($ref !== '' && function_exists('pm_post_by_ref')) {
        $p = pm_post_by_ref($ref, (string)($lead['brand'] ?? ''));
        if ($p) {
            $out['source_post'] = (string)$p['id'];
            $out['source_pillar'] = (string)($p['pillar'] ?? '');
            $out['source_format'] = (string)($p['format'] ?? '');
        }
    }
    return $out;
}

/** Where a lead came from as one string: src_tag, else the old string src, else ''. (Scout leads keep src as a list.) */
function pm_lead_src_tag(array $lead): string
{
    $t = trim((string)($lead['src_tag'] ?? ''));
    if ($t !== '') {
        return $t;
    }
    return is_string($lead['src'] ?? null) ? trim($lead['src']) : '';
}

/**
 * Reads the tracking parameters of a public page request: src, ref, utm_campaign, utm_source.
 * ['src_tag','ref' (4-character post code or ''),'referred_by' (lead id or '')]. ?ref=r-<lead id> is a referral; ?ref=host-<id> a host invitation.
 */
function pm_attrib_from_request(array $q): array
{
    $c = fn($k, int $n = 40) => substr(preg_replace('/[^a-z0-9_.-]/i', '', (string)($q[$k] ?? '')), 0, $n);
    $src = $c('src');
    $ref = $c('ref');
    $camp = $c('utm_campaign');
    $usrc = $c('utm_source', 20);
    $out = ['src_tag' => '', 'ref' => '', 'referred_by' => ''];
    $tag = '';
    foreach ([$ref, $src] as $v) {
        if (preg_match('/^r-([a-f0-9]{12})$/i', $v, $m)) {
            $out['referred_by'] = strtolower($m[1]);
            $tag = $tag ?: 'r-' . strtolower($m[1]);
        } elseif (preg_match('/^host-[a-z0-9]{3,24}$/i', $v)) {
            $tag = $tag ?: $v;
        }
    }
    foreach ([$camp, $ref, $src] as $v) { // a 4-character code, bare or after a channel (fb-K7Q2)
        if ($out['ref'] === '' && (preg_match('/^[A-Za-z0-9]{4}$/', $v) || preg_match('/^(?:fb|ig|li|wa|x|tt|gb|yt)-([A-Za-z0-9]{4})$/i', $v, $m))) {
            $code = strtoupper($m[1] ?? $v);
            if ($v !== $camp || $camp !== '') {
                $out['ref'] = $code;
            }
        }
    }
    if ($src !== '' && isset(PM_BIO_REFS[strtoupper($src)])) {
        $src = PM_BIO_REFS[strtoupper($src)];
    }
    $out['src_tag'] = $tag ?: ($src ?: (($usrc !== '' && $camp !== '') ? $usrc . '-' . $camp : ($out['ref'] !== '' ? 'ref-' . $out['ref'] : '')));
    return $out;
}

/** Leads of a brand created in the last $days days, with the fields the scoreboard and funnel need. */
function pm_attrib_leads(string $brand, int $days = 90): array
{
    $brand = $brand === 'travel' ? 'travel' : 'promanaged';
    $cut = date('Y-m-d', time() - $days * 86400);
    $out = [];
    foreach (pm_load('leads', fn() => []) as $l) {
        if (($l['brand'] ?? 'promanaged') !== $brand || substr((string)($l['created'] ?? ''), 0, 10) < $cut) {
            continue;
        }
        $ch = (string)($l['channel'] ?? '');
        if (!in_array($ch, ['whatsapp', 'web', 'facebook', 'instagram', 'call', 'walkin'], true)) {
            $ch = match ((string)($l['source'] ?? '')) {
                'web' => 'web', 'facebook' => 'facebook', 'instagram' => 'instagram', default => 'other',
            };
        }
        $aud = (string)($l['audience'] ?? '');
        $out[] = ['id' => (string)$l['id'], 'created' => substr((string)$l['created'], 0, 10), 'status' => (string)($l['status'] ?? ''), 'src_tag' => pm_lead_src_tag($l), 'channel' => $ch,
            'post_id' => (string)($l['source_post'] ?? ''), 'ref' => (string)($l['source_ref'] ?? ''), 'audience' => in_array($aud, ['host', 'traveller'], true) ? $aud : 'unknown',
            'first_touch_at' => (string)($l['first_touch_at'] ?? ''), 'first_reply_at' => (string)($l['first_reply_at'] ?? '')];
    }
    return $out;
}

const PM_ENQ_CHANNELS = ['whatsapp' => 'WhatsApp', 'messenger' => 'Messenger', 'instagram' => 'Instagram', 'call' => 'Phone call', 'walkin' => 'Walked in', 'linkedin' => 'LinkedIn'];
const PM_ENQ_SOURCES = ['post-ref' => ['Our post (I have the code)', 'ref'], 'page-wa' => ['Page WhatsApp button', 'page-wa'], 'status' => ['WhatsApp Status', 'wa-status'], 'referral' => ['Someone recommended us', 'referral'],
    'group' => ['A group', 'group'], 'walk-in' => ['Walk-in', 'walkin'], 'other' => ['Other', 'other']];

/**
 * Logs an enquiry that arrived by hand (WhatsApp, a call, a walk-in): d {name, phone, channel, source, need, ref, brand}.
 * Becomes a lead with status "replied" (or merges into the lead with the same phone), records the source and the first-touch time.
 * Travel Malawi: a traveller asking for a room goes to the demand ledger instead. Returns ['ok','msg','id','merged','demand'].
 */
function pm_log_enquiry(array $d): array
{
    $brand = ($d['brand'] ?? pm_brand()) === 'travel' ? 'travel' : 'promanaged';
    $clip = fn($v, int $n) => mb_substr(trim(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', (string)$v)), 0, $n);
    $name = $clip($d['name'] ?? '', 80);
    $phone = $clip($d['phone'] ?? '', 40);
    $need = $clip($d['need'] ?? '', 500);
    if ($name === '' && $phone === '') {
        return ['ok' => false, 'msg' => 'Give a name or a phone number.'];
    }
    $chan = (string)($d['channel'] ?? 'whatsapp');
    $chan = isset(PM_ENQ_CHANNELS[$chan]) ? $chan : 'whatsapp';
    $srcKey = (string)($d['source'] ?? 'other');
    $srcKey = isset(PM_ENQ_SOURCES[$srcKey]) ? $srcKey : 'other';
    $refText = $clip($d['ref'] ?? '', 60);
    $ref = pm_parse_ref($refText . ' ' . $need) ?: (preg_match('/^[A-Za-z0-9]{4}$/', $refText) ? strtoupper($refText) : '');
    $tag = $srcKey === 'post-ref' ? ($ref !== '' ? 'ref-' . $ref : 'post') : PM_ENQ_SOURCES[$srcKey][1];
    $leadChan = ['messenger' => 'facebook', 'linkedin' => 'other'][$chan] ?? $chan;
    if ($brand === 'travel' && pm_classify_audience($need) === 'traveller') {
        $r = pm_traveller_demand_add(['brand' => 'travel', 'name' => $name, 'contact' => $phone ?: PM_ENQ_CHANNELS[$chan], 'msg' => $need, 'src_tag' => $tag, 'ref' => $ref]);
        return ['ok' => true, 'demand' => true, 'id' => $r['id'], 'merged' => false, 'msg' => 'Saved as a traveller request (not a host lead).'];
    }
    $note = 'Logged by hand (' . PM_ENQ_CHANNELS[$chan] . ', from ' . PM_ENQ_SOURCES[$srcKey][0] . ($ref !== '' ? ', code ' . $ref : '') . ').';
    $r = pm_web_lead(['brand' => $brand, 'kind' => 'enquiry', 'name' => $name, 'business' => $name, 'phone' => $phone, 'message' => $need, 'source' => 'social', 'status' => 'replied', 'channel' => $leadChan,
        'src_tag' => $tag, 'ref' => $ref, 'label' => 'Enquiry logged by hand', 'note' => $note, 'first_touch_at' => date('c')]);
    return ['ok' => true, 'id' => $r['id'], 'merged' => $r['merged'], 'msg' => $r['merged'] ? 'Added to the existing lead with that phone number.' : 'Lead added (status Replied).'];
}

function pm_do_log_enquiry(string $vb): array
{
    $back = in_array($_POST['back'] ?? '', ['results', 'growth', 'whatsapp'], true) ? $_POST['back'] : 'results';
    $r = pm_log_enquiry(['brand' => $vb, 'name' => $_POST['name'] ?? '', 'phone' => $_POST['phone'] ?? '', 'channel' => $_POST['channel'] ?? '', 'source' => $_POST['source'] ?? '',
        'need' => $_POST['need'] ?? '', 'ref' => $_POST['ref'] ?? '']);
    return ['msg' => $r['msg'], 'kind' => $r['ok'] ? 'ok' : 'err', 'to' => $back === 'whatsapp' ? 'whatsapp' : 'social&view=' . $back];
}

/** The "Log an enquiry" card (Results, WhatsApp and Growth). */
function pm_log_enquiry_card(string $vb, string $back = 'results'): string
{
    $opt = fn(array $a) => implode('', array_map(fn($k, $v) => '<option value="' . pm_h($k) . '">' . pm_h(is_array($v) ? $v[0] : $v) . '</option>', array_keys($a), $a));
    return '<div class="card"><h2>Log an enquiry</h2><p class="hint">Someone asked on WhatsApp, by phone or at the door? Log it in ten seconds so we know which post, button or person brought them.</p>'
        . pm_inb_form('log_enquiry', '<input type="hidden" name="back" value="' . pm_h($back) . '">'
            . '<div class="row"><div><label>Name</label><input type="text" name="name" maxlength="80"></div><div><label>Phone / WhatsApp</label><input type="text" name="phone" maxlength="40"></div>'
            . '<div><label>They asked on</label><select name="channel">' . $opt(PM_ENQ_CHANNELS) . '</select></div>'
            . '<div><label>They found us through</label><select name="source">' . $opt(PM_ENQ_SOURCES) . '</select></div>'
            . '<div><label>Post code (if they sent one, like K7Q2)</label><input type="text" name="ref" maxlength="20"></div></div>'
            . '<label>What they need</label><textarea name="need" rows="2" maxlength="500"></textarea><div class="btns"><button class="btn small primary">Log it</button></div>')
        . '</div>';
}

function pm_panel_results_logenquiry(string $vb, array $ctx = []): string { return pm_log_enquiry_card($vb, 'results'); }
function pm_panel_wa_top_logenquiry(string $vb, array $ctx = []): string { return pm_log_enquiry_card($vb, 'whatsapp'); }

/** Lead card: "Came from post ...", "Referred by ...", and a manual "From post" choice. Only for leads that wrote to us. */
function pm_panel_lead_card_attrib(string $vb, array $ctx = []): string
{
    $l = (array)($ctx['lead'] ?? []);
    if (!$l || (empty($l['src_tag']) && empty($l['source_post']) && !in_array($l['source'] ?? '', ['web', 'facebook', 'instagram', 'social'], true))) {
        return '';
    }
    $brand = ($l['brand'] ?? 'promanaged') === 'travel' ? 'travel' : 'promanaged';
    $posts = array_values(array_filter(pm_social_posts_safe(), fn($p) => ($p['brand'] ?? 'promanaged') === $brand && (!empty($p['fb_id']) || ($p['status'] ?? '') === 'published')));
    usort($posts, fn($a, $b) => strcmp((string)($b['when'] ?? ''), (string)($a['when'] ?? '')));
    $cur = array_values(array_filter($posts, fn($p) => $p['id'] === ($l['source_post'] ?? '')))[0] ?? null;
    $h = '<div class="hint attrib">';
    if ($cur) {
        $h .= '<b>Came from post:</b> ' . pm_h((string)($cur['headline'] ?: mb_substr((string)$cur['caption'], 0, 60))) . ' (' . pm_h((string)($cur['pillar'] ?? '')) . ')';
    } elseif (pm_lead_src_tag($l) !== '') {
        $h .= '<b>Came from:</b> ' . pm_h(pm_lead_src_tag($l)) . (!empty($l['source_ref']) ? ' (code ' . pm_h($l['source_ref']) . ')' : '');
    } else {
        $h .= '<b>Came from:</b> not recorded';
    }
    if (!empty($l['referred_by'])) {
        $rl = pm_load('leads', fn() => [])[$l['referred_by']] ?? null;
        $h .= ' · <b>Referred by</b> ' . pm_h((string)($rl['name'] ?? 'an existing client'));
    }
    if (!empty($l['channel'])) {
        $h .= ' · ' . pm_h((string)$l['channel']);
    }
    $h .= pm_inb_form('lead_set_post', '<input type="hidden" name="id" value="' . pm_h((string)$l['id']) . '"><select name="post" aria-label="From post"><option value="">From post…</option>'
        . implode('', array_map(fn($p) => '<option value="' . pm_h($p['id']) . '"' . (($l['source_post'] ?? '') === $p['id'] ? ' selected' : '') . '>' . pm_h(substr((string)$p['when'], 0, 10) . ' · ' . mb_substr((string)($p['headline'] ?: $p['caption']), 0, 50)) . '</option>', array_slice($posts, 0, 40)))
        . '</select> <button class="btn small">Save</button>', 'inl');
    return $h . '</div>';
}

/** Choose by hand which post a lead came from. */
function pm_do_lead_set_post(string $vb): array
{
    $id = (string)($_POST['id'] ?? '');
    $pid = (string)($_POST['post'] ?? '');
    $leads = pm_leads();
    if (!isset($leads[$id])) {
        return ['msg' => 'That lead is gone.', 'kind' => 'err', 'to' => 'agents'];
    }
    $post = null;
    foreach (pm_social_posts_safe() as $p) {
        if (($p['id'] ?? '') === $pid) {
            $post = $p;
        }
    }
    $leads[$id]['source_post'] = $post ? $pid : '';
    $leads[$id]['source_ref'] = $post && function_exists('pm_post_ref') ? pm_post_ref($post) : '';
    $leads[$id]['source_pillar'] = (string)($post['pillar'] ?? '');
    $leads[$id]['source_format'] = (string)($post['format'] ?? '');
    pm_lead_note($leads[$id], $post ? 'Marked as coming from the post: ' . ($post['headline'] ?? '') : 'Post source cleared');
    pm_leads_save($leads);
    return ['msg' => $post ? 'Saved.' : 'Cleared.', 'kind' => 'ok', 'to' => 'agents&status=all#l' . preg_replace('/[^a-z0-9]/i', '', $id)];
}
