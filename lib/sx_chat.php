<?php
/**
 * Talk it over: the owner chats with the agent who wrote a message, under any message box (lead emails and WhatsApps, answers to replies, follow-ups, posts,
 * comment and inbox replies, the Facebook bio). "Shorter", "warmer", "mention the free listing", "why did you open like that?": the agent answers in a line and,
 * when the owner asks for a change, puts the whole new version in the box (Undo puts the old one back; nothing is saved until the owner saves the form).
 * One cheap AI call a turn, with the last few turns, the business's facts and, on a lead, what we know about them and what they wrote.
 * The same guards as New wording: a sentence with a number nobody gave is dropped, a new link is refused, {placeholders} must stay.
 * When the wish holds for every message ("never say 'I hope this finds you well'"), the agent offers it as a standing rule: Remember adds it to the business's
 * tone of voice or Never say (Social > Voice, the one editor of those), so every writer follows it from then on.
 */

/** What each box is: [the agent's department, what it is called, characters at most, the polish channel]. */
const PM_CC_KINDS = [
    'email' => ['writers', 'email', 5000, 'email'],
    'followup' => ['followup', 'follow-up email', 5000, 'email'],
    'reply' => ['replies', 'answer to their message', 5000, 'reply'],
    'whatsapp' => ['writers', 'WhatsApp message', 1200, 'whatsapp'],
    'post' => ['social', 'post', 2200, 'post'],
    'comment' => ['social', 'reply on social media', 1500, 'reply'],
    'bio' => ['social', 'Page bio', 255, 'profile'],
];

/** The kind of a box from what the browser knows: its name, the form's "do" and "which", or data-chat on the box. */
function pm_cc_kind(string $hint, string $name, string $do, string $which): string
{
    if (isset(PM_CC_KINDS[$hint])) {
        return $hint;
    }
    if ($name === 'whatsapp') {
        return $do === 'save_reply' ? 'reply' : 'whatsapp'; // the WhatsApp answer to their message is the Replies head's
    }
    if (in_array($name, ['email_body', 'body'], true)) {
        return $do === 'save_reply' ? 'reply' : ($which === 'followup' ? 'followup' : 'email');
    }
    if (in_array($name, ['caption', 'first_comment', 'script'], true)) {
        return 'post';
    }
    return $name === 'own' ? 'bio' : 'comment';
}

/** What the agent knows about the lead behind the box (nothing for a box that is not a lead's). */
function pm_cc_lead(string $id): array
{
    $l = $id !== '' ? (pm_leads()[$id] ?? null) : null;
    if (!$l || pm_brand_norm((string)($l['brand'] ?? 'promanaged')) !== pm_brand()) {
        return [];
    }
    $in = '';
    foreach (array_reverse((array)($l['thread'] ?? [])) as $m) {
        if (($m['dir'] ?? '') === 'in' && trim((string)($m['text'] ?? '')) !== '') {
            $in = mb_substr(trim((string)$m['text']), 0, 900);
            break;
        }
    }
    $r = (array)($l['research'] ?? []);
    return array_filter(['business' => (string)($l['name'] ?? ''), 'kind' => (string)($l['type'] ?? ''), 'town' => (string)($l['city'] ?? ''), 'contact_name' => (string)($l['contact'] ?? ''),
        'what_we_found' => array_slice(array_filter(array_map(fn($x) => (string)($x['fact'] ?? ''), (array)($r['discoveries'] ?? []))), 0, 3), 'personal_line' => (string)($r['personal'] ?? ''),
        'their_last_message' => $in]);
}

/**
 * One turn of the chat. $chat: earlier turns [['who' => 'owner'|'agent', 'said' => '...'], ...]. Returns ['say', 'text' ('' = unchanged), 'lesson', 'lesson_for', 'agent'].
 * Throws when the AI cannot be reached.
 */
function pm_cc_turn(string $kind, string $text, string $ask, array $chat, array $lead = [], string $subject = '', int $max = 0): array
{
    [$dept, $what, $cap, $chan] = PM_CC_KINDS[$kind] ?? PM_CC_KINDS['comment'];
    $cap = $max > 0 ? min($cap, $max) : $cap;
    $agent = pm_agent_first($dept);
    $s = pm_settings();
    $ask = mb_substr(trim($ask), 0, 600);
    $chat = array_slice(array_values(array_filter(array_map(fn($t) => ['who' => ($t['who'] ?? '') === 'owner' ? 'owner' : 'you', 'said' => mb_substr(trim((string)($t['said'] ?? '')), 0, 600)], $chat), fn($t) => $t['said'] !== '')), -8);
    $system = "You are $agent, " . (PM_AGENT_ROLES[$dept] ?? 'Writer') . " at {$s['company_name']}. You wrote this $what and the owner is chatting with you about it. "
        . pm_agents_company_brief('tiny') . "\n"
        . 'When the owner asks for a change, make it and give the WHOLE new version in "text": change what they asked for and keep the rest as it is. When they only ask a question or want your view, answer it and leave "text" empty. '
        . 'If a wish would break a rule below, say so plainly and offer the closest honest version. '
        . 'Rules: use only the facts above, the text, what we know about the lead and what the owner says: no new numbers, prices, dates, places, features, claims or links. Keep every link and every {placeholder} exactly as written. Keep the language of the text unless the owner asks for another. '
        . ($chan === 'email' || $chan === 'reply' ? 'No sign-off and no name at the end: the app adds the signature. ' : '')
        . ($kind === 'whatsapp' ? 'No greeting line and no sign-off: the app adds them. ' : '')
        . ($chan === 'reply' && $lead ? 'It is our answer to their last message: it must still answer it. ' : '')
        . "At most $cap characters. Plain, warm, human words, short sentences, never pushy. "
        . '"say": one or two short sentences to the owner, in the first person, plain (what you changed, or your answer). '
        . '"lesson": only when the owner\'s wish should hold for every message we write (a word to avoid, a tone, a habit), that wish as one short standing rule; otherwise empty. "lesson_for": "never" for something never to say or do, else "voice". '
        . 'Reply JSON only: {"say":"","text":"","lesson":"","lesson_for":"voice"}';
    $user = json_encode(array_filter(['the_' . str_replace([' ', '-'], '_', $what) => $text, 'its_subject' => $subject, 'the_lead' => $lead ?: null, 'chat_so_far' => $chat ?: null, 'owner_now' => $ask], fn($v) => $v !== null && $v !== ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $out = pm_agent_json(pm_claude($system, $user, false, min(2200, 260 + (int)(mb_strlen($text) / 2.2)), 'cheap'));
    if (!is_array($out)) {
        throw new RuntimeException("$agent did not answer. Try again.");
    }
    $say = pm_swarm_clean($out['say'] ?? '', 400);
    $new = trim(strip_tags(is_scalar($out['text'] ?? null) ? (string)$out['text'] : ''));
    $notes = [];
    if ($new !== '' && $new !== trim($text)) {
        $src = implode("\n", [$text, $subject, $ask, implode("\n", array_column($chat, 'said')), json_encode($lead, JSON_UNESCAPED_UNICODE), pm_agents_company_brief('tiny')]);
        $kept = implode("\n\n", array_filter(array_map(fn($p) => pm_regen_sourced($p, $src), preg_split('/\R{2,}/u', $new) ?: []), fn($x) => $x !== ''));
        $kept !== $new && $notes[] = 'I left out a sentence with a number that is not in our facts.';
        $new = $kept;
        preg_match_all('/\{[a-z_]+\}/', $text, $ph);
        $srcLinks = pm_regen_links($src);
        if ($new !== '' && array_diff(pm_regen_links($new), $srcLinks)) {
            [$new, $notes] = ['', ['I wrote a link nobody gave me, so I kept your text. Ask again and I will leave it out.']];
        } elseif ($new !== '' && array_diff($ph[0], (preg_match_all('/\{[a-z_]+\}/', $new, $ph2) ? $ph2[0] : []))) {
            [$new, $notes] = ['', ['I lost a {placeholder} the app fills in, so I kept your text. Ask again.']];
        }
        if ($new !== '') {
            $new = mb_substr(pm_human_polish($new, $chan), 0, $cap);
        }
    } else {
        $new = '';
    }
    $lesson = pm_swarm_clean($out['lesson'] ?? '', 160);
    return ['say' => trim($say . ' ' . implode(' ', $notes)) ?: ($new !== '' ? 'Done: the new version is in the box.' : 'Tell me what to change.'), 'text' => $new,
        'lesson' => $lesson, 'lesson_for' => ($out['lesson_for'] ?? '') === 'never' ? 'never' : 'voice', 'agent' => $agent . ' · ' . (PM_AGENT_ROLES[$dept] ?? 'Writer')];
}

/** Remember: a standing rule from the chat added to the business's tone of voice or Never say (Social > Voice). Returns the message for the owner. */
function pm_cc_remember(string $brand, string $lesson, string $for): string
{
    $lesson = rtrim(pm_swarm_clean($lesson, 160), '. ');
    if ($lesson === '') {
        return '';
    }
    $for = $for === 'never' ? 'never' : 'voice';
    $added = false;
    pm_update('settings', function (array $s) use ($brand, $lesson, $for, &$added) {
        $blk = pm_brand_block($s, $brand);
        $blk['brain'] = (array)($blk['brain'] ?? []) + pm_brain($brand);
        $now = trim((string)($blk['brain'][$for] ?? ''));
        if (mb_stripos($now, $lesson) === false && mb_strlen($now) < 1200) {
            $blk['brain'][$for] = trim($now . ($now === '' ? '' : (preg_match('/[.!?]$/', $now) ? ' ' : '. ')) . $lesson . '.');
            $added = true;
        }
        pm_brand_block_put($s, $brand, $blk);
        return $s;
    }, 'pm_default_settings');
    $added && pm_talk($brand, 'owner', 'New standing rule for every writer: ' . $lesson . '.');
    return $added ? 'Remembered: every writer follows it from now on (Social > Voice, ' . ($for === 'never' ? 'Never say' : 'Tone of voice') . ').' : 'That rule is already there.';
}
