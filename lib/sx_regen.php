<?php
/**
 * New wording, cheaply. One small AI call (the cheap model, no web, no research, no brief beyond the business name and voice) writes text that already exists again in fresh words:
 * a planned post (headline, subline, caption, hashtags, reel script), a lead's first email and WhatsApp, a follow-up, an answer to a reply.
 * The original is the only source: a sentence with a number that is not in the original or the owner's note is dropped, a field that loses or gains a link keeps its old text,
 * and the owner's own price line on a post from a photo is put back exactly. The previous wording is kept once, so one press of Undo brings it back.
 *   pm_regen_words($fields, $kind, $note)     the engine: field => text in, field => text out (a field it could not improve keeps its text)
 *   pm_regen_post($p, $note)                  a post with new words (needs saving by the caller), its old words in prev_words
 *   pm_regen_lead($lead, $slot, $fields, $note) a lead with new words in drafts / followup_draft / reply_draft, the old ones in reword_prev
 */

/** What each field may be: [max characters, how to keep it]. */
const PM_REGEN_FIELDS = [
    'headline' => [70, 'at most 6 words, for the picture'],
    'sub' => [110, 'at most 10 words, under the headline'],
    'caption' => [2200, 'about the same length, the same paragraphs; a line that is only a price stays exactly as it is'],
    'hashtags' => [200, 'the same number of hashtags or fewer, space separated'],
    'script' => [1500, 'a short video script, same scenes, same length'],
    'email_subject' => [90, 'a short email subject, no capitals shouting'],
    'subject' => [90, 'a short email subject'],
    'email_body' => [3000, 'the same email in fresh words, same length, same ask'],
    'body' => [3000, 'the same email answer in fresh words, same length, same answer'],
    'whatsapp' => [1200, 'the same WhatsApp message in fresh words, short lines, same ask'],
];

/** Every link in a text, lower-cased and without a trailing full stop. */
function pm_regen_links(string $t): array
{
    preg_match_all('~(?:https?://|www\.)[^\s<>"\')]+~i', $t, $m);
    $out = array_map(fn($u) => rtrim(strtolower($u), '.,;:!?'), $m[0]);
    sort($out);
    return array_values(array_unique($out));
}

/**
 * The engine. $fields: name => current text (names from PM_REGEN_FIELDS). $kind: 'post', 'email', 'whatsapp' or 'reply' (only steers the polish).
 * Returns the same names with new text; a field the AI left out, emptied, or changed in facts or links keeps its old text. Throws when the AI cannot be reached.
 */
function pm_regen_words(array $fields, string $kind, string $note = ''): array
{
    $fields = array_filter(array_intersect_key(array_map(fn($v) => trim((string)$v), $fields), PM_REGEN_FIELDS), fn($v) => $v !== '');
    if (!$fields) {
        return [];
    }
    $s = pm_settings();
    $voice = trim((string)(pm_brain()['voice'] ?? ''));
    $note = mb_substr(trim($note), 0, 300);
    $system = "You reword marketing text for {$s['company_name']}." . ($voice !== '' ? " Voice: $voice" : '')
        . "\nWrite every field again in fresh words so it reads new, keeping exactly the same facts, offer, names, prices, dates, links and language. "
        . 'Add nothing that is not in the original or in the owner\'s note: no new numbers, prices, places, features, claims or links. Keep each link exactly as written. '
        . 'Plain, warm, human words, short sentences, no exclamation marks in a row, no hashtags inside the text fields. '
        . 'If the owner left a note, follow it within those rules. Never start a caption or message with "Hello" or the business name. Reply JSON only: an object with the field names as its keys.';
    $user = json_encode(['fields' => $fields, 'how' => array_map(fn($k) => PM_REGEN_FIELDS[$k][1], array_combine(array_keys($fields), array_keys($fields))), 'owner_note' => $note], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $out = pm_agent_json(pm_claude($system, $user, false, min(1800, 250 + (int)(array_sum(array_map('mb_strlen', $fields)) / 2.5)), 'cheap'));
    if (is_array($out['fields'] ?? null)) { // models often answer in the shape they were sent
        $out = $out['fields'];
    }
    if (!is_array($out)) {
        throw new RuntimeException('The AI did not return new wording. Try again.');
    }
    $res = [];
    foreach ($fields as $k => $old) {
        $new = trim(strip_tags(is_scalar($out[$k] ?? null) ? (string)$out[$k] : (is_array($out[$k] ?? null) ? implode(' ', array_map('strval', $out[$k])) : '')));
        $src = $old . "\n" . $note;
        if ($new !== '' && $k !== 'hashtags') { // a sentence with a number nobody gave goes; paragraphs are kept
            $new = implode("\n\n", array_filter(array_map(fn($para) => pm_regen_sourced($para, $src), preg_split('/\R{2,}/u', $new) ?: []), fn($x) => $x !== ''));
        }
        if ($k === 'hashtags') {
            preg_match_all('/#[\p{L}\p{N}_]{2,40}/u', $new, $hm);
            $max = max(1, min(5, count(preg_split('/\s+/', $old))));
            $new = implode(' ', array_slice(array_values(array_unique($hm[0])), 0, $max));
        }
        if ($new === '' || pm_regen_links($new) !== pm_regen_links($old) || $new === $old) {
            $res[$k] = $old;
            continue;
        }
        $new = mb_substr($new, 0, PM_REGEN_FIELDS[$k][0]);
        if (function_exists('pm_human_polish') && in_array($k, ['caption', 'email_body', 'body', 'whatsapp'], true)) {
            $new = pm_human_polish($new, $kind === 'post' ? 'post' : ($k === 'whatsapp' ? 'whatsapp' : 'email'));
        }
        $res[$k] = $new;
    }
    return $res;
}

/** The text with every sentence that holds a number not found in $src taken out (thousands separators ignored). */
function pm_regen_sourced(string $text, string $src): string
{
    preg_match_all('/\d+(?:[.,]\d+)*/', $src, $m);
    $ok = array_map(fn($x) => str_replace(',', '', $x), $m[0]);
    $keep = [];
    foreach (preg_split('/(?<=[.!?])[ \t]+/u', trim($text)) ?: [] as $sent) {
        preg_match_all('/\d+(?:[.,]\d+)*/', $sent, $mm);
        if (!array_filter($mm[0], fn($x) => !in_array(str_replace(',', '', rtrim($x, '.,')), $ok, true))) {
            $keep[] = $sent;
        }
    }
    return trim(implode(' ', $keep));
}

/* ---------------- posts ---------------- */

/** The words of a post that can be written again, as fields for the engine. */
function pm_regen_post_fields(array $p): array
{
    $f = ['caption' => (string)($p['caption'] ?? ''), 'hashtags' => implode(' ', (array)($p['hashtags'] ?? []))];
    if (in_array($p['format'] ?? '', ['image', 'carousel', 'story'], true)) {
        $f['headline'] = (string)($p['headline'] ?? '');
        $f['sub'] = (string)($p['sub'] ?? '');
    }
    if (($p['format'] ?? '') === 'reel') {
        $f['script'] = (string)($p['script'] ?? '');
    }
    return $f;
}

/** A post with new words, checked again; the old words are kept in prev_words. Approval is undone: the owner has not read these words yet. */
function pm_regen_post(array $p, string $note = ''): array
{
    $old = pm_regen_post_fields($p);
    $new = pm_regen_words($old, 'post', $note);
    $price = trim((string)($p['price']['text'] ?? ''));
    if ($price !== '' && isset($new['caption']) && stripos($new['caption'], $price) === false && function_exists('pm_pp_caption_with_price')) { // the owner's price line, exactly as typed
        $new['caption'] = pm_pp_caption_with_price(trim(preg_replace('/^.*\b(MWK|USD|ZAR|ZMW|GBP|EUR)\b.*$/mi', '', $new['caption'])), $price);
    }
    $p['prev_words'] = $old;
    foreach ($new as $k => $v) {
        $p[$k] = $k === 'hashtags' ? array_values(array_filter(preg_split('/\s+/', $v))) : $v;
    }
    if (in_array($p['status'], ['approved', 'review'], true)) {
        $p['status'] = 'draft';
        $p['approved_hash'] = '';
        $p['retry_at'] = '';
    }
    $p['review_note'] = '';
    $p = pm_social_resolve($p, pm_social_lint_post($p));
    return pm_social_log_add($p, 'new_wording', $note);
}

/** The post with the words it had before the last new wording. */
function pm_regen_post_undo(array $p): array
{
    foreach ((array)($p['prev_words'] ?? []) as $k => $v) {
        $p[$k] = $k === 'hashtags' ? array_values(array_filter(preg_split('/\s+/', (string)$v))) : (string)$v;
    }
    unset($p['prev_words']);
    if (in_array($p['status'], ['approved', 'review'], true)) {
        $p['status'] = 'draft';
        $p['approved_hash'] = '';
    }
    return pm_social_log_add(pm_social_resolve($p, pm_social_lint_post($p)), 'wording_undone');
}

/** Social > Plan, "New wording" on one post or on the ticked ones (at most 8 at a time: one small AI call each). Returns [message, kind]. */
function pm_regen_posts(string $vb, array $ids, string $note = ''): array
{
    if (!pm_agents_ready()) {
        return ['The AI needs a key first (Operations > Connections).', 'err'];
    }
    $todo = array_values(array_filter(pm_social_posts(), fn($p) => ($p['brand'] ?? 'promanaged') === $vb && in_array($p['id'], $ids, true)
        && in_array($p['status'], ['draft', 'review', 'approved', 'needs_edit', 'needs_video', 'needs_asset'], true)));
    if (!$todo) {
        return [$ids ? 'Only posts that have not gone out can get new wording.' : 'Tick the posts first.', 'err'];
    }
    $todo = array_slice($todo, 0, 8);
    $done = [];
    $fail = '';
    foreach ($todo as $p) { // the AI call happens outside the posts lock; the result is merged into the post as it is then
        try {
            $np = pm_regen_post($p, $note);
        } catch (Throwable $e) {
            $fail = $e->getMessage();
            continue;
        }
        $keys = array_merge(array_keys(pm_regen_post_fields($p)), ['prev_words', 'status', 'approved_hash', 'retry_at', 'review_note', 'lint', 'warn', 'log', 'error']);
        pm_social_patch($p['id'], fn(array $cur) => array_replace($cur, array_intersect_key($np, array_flip($keys))));
        $done[] = $np;
    }
    foreach ($done as $np) { // the picture shows the new headline
        if (in_array($np['format'] ?? '', ['image', 'carousel', 'story'], true)) {
            try {
                pm_social_card($np);
            } catch (Throwable) {
            }
        }
    }
    if (!$done) {
        return ['No new wording: ' . ($fail ?: 'try again.'), 'err'];
    }
    $n = count($done);
    return [($n === 1 ? 'New wording written' : "$n posts have new wording") . '. Read ' . ($n === 1 ? 'it' : 'them') . ' before approving' . ($n < count($ids) && count($ids) > 8 ? ' (8 at a time)' : '') . '. Undo brings the old words back.', 'ok'];
}

/* ---------------- leads ---------------- */

/**
 * A lead with new words in $slot ('drafts', 'followup_draft' or 'reply_draft'). $fields is what is on screen (so unsaved edits are the starting point).
 * The old words go to reword_prev; a queued first email loses its approval, because the owner has not read the new words.
 */
function pm_regen_lead(array $lead, string $slot, array $fields, string $note = ''): array
{
    $kind = $slot === 'reply_draft' ? 'reply' : 'email';
    $new = pm_regen_words($fields, $kind, $note);
    if (!$new) {
        throw new RuntimeException('There is no text to reword yet.');
    }
    $lead['reword_prev'] = ['slot' => $slot, 'data' => array_intersect_key((array)($lead[$slot] ?? []), $fields + ['email_subject' => 1, 'email_body' => 1, 'whatsapp' => 1, 'subject' => 1, 'body' => 1])];
    $lead[$slot] = array_replace((array)($lead[$slot] ?? []), $new);
    if ($slot === 'drafts') {
        unset($lead['approved_at'], $lead['send_after']);
    }
    pm_lead_note($lead, 'New wording' . ($note !== '' ? ': ' . mb_substr($note, 0, 80) : ''));
    return $lead;
}

/** The lead with the words it had before the last new wording. */
function pm_regen_lead_undo(array $lead): array
{
    $pr = (array)($lead['reword_prev'] ?? []);
    $slot = (string)($pr['slot'] ?? '');
    if (in_array($slot, ['drafts', 'followup_draft', 'reply_draft'], true)) {
        $lead[$slot] = array_replace((array)($lead[$slot] ?? []), (array)($pr['data'] ?? []));
        if ($slot === 'drafts') {
            unset($lead['approved_at'], $lead['send_after']);
        }
    }
    unset($lead['reword_prev']);
    return $lead;
}

/** The two buttons under a lead's message box: new wording (asks for an optional note) and, after one, undo. */
function pm_regen_lead_buttons(array $lead, string $slot): string
{
    if (!pm_agents_ready()) {
        return '';
    }
    $h = '<input type="hidden" name="reword_note" value=""><button class="btn small" name="do" value="reword" data-ask-opt="reword_note" title="One small AI call writes the text on screen again in fresh words: same facts and links, nothing added">New wording</button>';
    if (($lead['reword_prev']['slot'] ?? '') === $slot) {
        $h .= '<button class="btn small ghost" name="do" value="reword_undo" formnovalidate>Undo new wording</button>';
    }
    return $h;
}
